<?php

namespace LGL\Clever\Lib;

use LGL\Clever\Resource;

class Teacher extends Resource {

	const BASE_URL = "teachers";

	function getDistrict(array $query = []){
		return $this->getObjects(static::CLEVER_DISTRICT, $query);
	}

	function getSections(array $query = []){
		return $this->getObjects(static::CLEVER_SECTION, $query);
	}

	function getSchool(array $query = []){
		return $this->getObjects(static::CLEVER_SCHOOL, $query);
	}

	function getStudents(array $query = []){
		return $this->getObjects(static::CLEVER_STUDENT, $query);
	}

	function getEvents(array $query = []){
		return $this->getObjects(static::CLEVER_EVENT, $query);
	}

	function getGradeLevels(array $query = []){
		return $this->getObjects('grade_levels', $query);
	}

}