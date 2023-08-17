<?php

namespace LGL\Clever\Lib;

use LGL\Clever\Resource;

class Student extends Resource {

	const BASE_URL = "students";

	function getDistrict(array $query = []){
		return $this->getObjects(static::CLEVER_DISTRICT, $query);
	}

	function getSections(array $query = []){
		return $this->getObjects(static::CLEVER_SECTION, $query);
	}

	function getSchool(array $query = []){
		return $this->getObjects(static::CLEVER_SCHOOL, $query);
	}

	function getTeachers(array $query = []){
		return $this->getObjects(static::CLEVER_TEACHER, $query);
	}

	function getEvents(array $query = []){
		return $this->getObjects(static::CLEVER_EVENT, $query);
	}

	function getContacts(array $query = []){
		return $this->getObjects('contacts', $query);
	}

}