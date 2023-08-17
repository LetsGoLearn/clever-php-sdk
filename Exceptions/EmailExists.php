<?php

namespace LGL\Clever\Exceptions;


class EmailExists extends \Exception
{
    public function __construct($message = 'eMail already in use but the clever ID doesn\'t match.', $code = 1002, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
