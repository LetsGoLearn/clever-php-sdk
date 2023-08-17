<?php

namespace LGL\Clever\Exceptions;

class Exception extends \Exception {

	protected $context = [];

	function toArray(){
		return [
			"e.code"     => $this->getCode(),
			"e.message"  => $this->getMessage(),
			"e.file"     => $this->getFile(),
			"e.line"     => $this->getLine(),
			"e.previous" => serialize($this->getPrevious()),
		];
	}

	function serialize(){
		return json_encode($this->toArray());
	}

	function setContext(array $context){
		$this->context = $context;
	}

}