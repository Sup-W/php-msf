<?php
/**
 * 协程任务
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Coroutine;

use PG\MSF\Base\Exception;
use PG\MSF\Helpers\Context;
use PG\MSF\Controllers\Controller;
use PG\AOP\MI;

class Task
{
    use MI;

    /**
     * 协程任务的迭代器
     *
     * @var \Generator
     */
    public $routine;

    /**
     * 任务销毁标识
     *
     * @var bool
     */
    public $destroy = false;

    /**
     * 协程嵌套栈
     *
     * @var \SplStack
     */
    protected $stack;

    /**
     * 请求的控制器
     *
     * @var Controller
     */
    protected $controller;

    /**
     * 任务ID
     *
     * @var string
     */
    protected $id;

    /**
     * @var \Throwable
     */
    protected $exception;

    /**
     * 初始化方法
     *
     * @param \Generator $routine
     * @param Context $context
     * @param Controller $controller
     * @return $this
     */
    public function initialization(\Generator $routine, Context &$context, Controller &$controller)
    {
        $this->routine    = $routine;
        $this->context    = $context;
        $this->controller = $controller;
        $this->stack      = new \SplStack();
        $this->id         = $context->getLogId();
        return $this;
    }

    /**
     * @param \Throwable $exception
     */
    public function setException(\Throwable $exception)
    {
        $this->exception = $exception;
    }

    /**
     * 协程调度
     */
    public function run()
    {
        $routine = &$this->routine;
        try {
            if (!$routine) {
                return;
            }
            if ($this->exception) {
                throw $this->exception;
            }
            $value = $routine->current();
            //嵌套的协程
            if ($value instanceof \Generator) {
                $this->stack->push($routine);
                $routine = $value;
                return;
            }

            if ($value != null && $value instanceof IBase) {
                if ($value->isTimeout()) {
                    try {
                        $value->throwException();
                    } catch (\Exception $e) {
                        $this->handleTaskTimeout($e, $value);
                    }
                    unset($value);
                    $routine->send(false);
                } else {
                    $result = $value->getResult();
                    if ($result !== CNull::getInstance()) {
                        unset($value);
                        $routine->send($result);
                    }
                }

                while (!empty($this->stack) && !$this->routine->valid() && !$this->stack->isEmpty()) {
                    $result = $routine->getReturn();
                    $this->routine = $this->stack->pop();
                    $this->routine->send($result);
                }
            } else {
                if ($routine instanceof \Generator && $routine->valid()) {
                    $routine->send($value);
                } else {
                    if (count($this->stack) > 0) {
                        $result = $routine->getReturn();
                        $this->routine = $this->stack->pop();
                        $this->routine->send($result);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->exception = null;
            if (empty($value)) {
                $value = '';
            }
            $runTaskException = $this->handleTaskException($e, $value);
            if ($this->controller) {
                call_user_func([$this->controller, 'onExceptionHandle'], $runTaskException);
            } else {
                $routine->throw($runTaskException);
            }

            unset($value);
        }
    }

    public function handleTaskTimeout(\Throwable $e, $value)
    {
        if ($value != '') {
            $logValue = '';
            dumpInternal($logValue, $value, 0, false);
            $message = 'Yield ' . $logValue . ' message: ' . $e->getMessage();
        } else {
            $message = 'message: ' . $e->getMessage();
        }

        $runTaskException = new Exception($message, $e->getCode(), $e);
        $this->context->getLog()->warning($message);

        if (!empty($value) && $value instanceof IBase && method_exists($value, 'destroy')) {
            $value->destroy();
        }

        return $runTaskException;
    }

    public function handleTaskException(\Throwable $e, $value)
    {
        if ($value != '') {
            $logValue = '';
            dumpInternal($logValue, $value, 0, false);
            $message = 'Yield ' . $logValue . ' message: ' . $e->getMessage();
        } else {
            $message = $e->getMessage();
        }

        $runTaskException = new Exception($message, $e->getCode(), $e);

        while (!empty($this->stack) && !$this->stack->isEmpty()) {
            $this->routine = $this->stack->pop();
            try {
                $this->routine->throw($runTaskException);
                break;
            } catch (\Exception $e) {
            }
        }

        if (!empty($value) && $value instanceof IBase && method_exists($value, 'destroy')) {
            $value->destroy();
        }

        return $runTaskException;
    }

    /**
     * 销毁
     */
    public function destroy()
    {
        if (!empty($this->id)) {
            getInstance()->coroutine->taskMap[$this->id] = null;
            unset(getInstance()->coroutine->taskMap[$this->id]);
            getInstance()->coroutine->IOCallBack[$this->id] = null;
            unset(getInstance()->coroutine->IOCallBack[$this->id]);
            if (getInstance()::mode == 'console') {
                $this->controller->destroy();
            }
            $this->stack      = null;
            $this->controller = null;
            $this->id         = null;
        }
    }

    /**
     * [isFinished 判断该task是否完成]
     * @return boolean [description]
     */
    public function isFinished()
    {
        return !empty($this->stack) && $this->stack->isEmpty() && !$this->routine->valid();
    }

    public function getRoutine()
    {
        return $this->routine;
    }
}
