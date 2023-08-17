<?php

namespace LGL\Clever\Exceptions;


class ExceededCleverIdCount extends \Exception
{
    public function __construct($message = 'Exceeded Clever ID count', $code = 1005, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
