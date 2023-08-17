<?php

namespace LGL\Clever\Lib;

use LGL\Clever\Resource;

class School extends Resource {

	const BASE_URL = "schools";

	function getDistrict(array $query = []){
		return $this->getObjects(static::CLEVER_DISTRICT, $query);
	}

	function getSections(array $query = []){
		return $this->getObjects(static::CLEVER_SECTION, $query);
	}

	function getStudents(array $query = []){
		return $this->getObjects(static::CLEVER_STUDENT, $query);
	}

	function getTeachers(array $query = []){
		return $this->getObjects(static::CLEVER_TEACHER, $query);
	}

	function getEvents(array $query = []){
		return $this->getObjects(static::CLEVER_EVENT, $query);
	}
}