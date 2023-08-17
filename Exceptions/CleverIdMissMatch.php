<?php

namespace LGL\Clever\Exceptions;


class CleverIdMissMatch extends \Exception
{
    public function __construct($message = 'The system users Clever ID doesn\'t match the provided user at Clever.', $code = 1001, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
