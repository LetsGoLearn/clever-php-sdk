<?php

namespace LGL\Clever\Lib;

use LGL\Clever\Resource;

class Section extends Resource {

	const BASE_URL = "sections";

	function getDistrict(array $query = []){
		return $this->getObjects(static::CLEVER_DISTRICT, $query);
	}

	function getSchool(array $query = []){
		return $this->getObjects(static::CLEVER_SCHOOL, $query);
	}

	function getStudents(array $query = []){
		return $this->getObjects(static::CLEVER_STUDENT, $query);
	}

	function getTeachers(array $query = []){
		return $this->getObjects(static::CLEVER_TEACHER, $query);
	}

	function getTeacher(array $query = []){
		return $this->getObjects('teacher', $query);
	}

	function getEvents(array $query = []){
		return $this->getObjects(static::CLEVER_EVENT, $query);
	}

}