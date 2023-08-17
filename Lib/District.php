<?php

namespace LGL\Clever\Lib;

use LGL\Clever\Resource;

class District extends Resource {

	const BASE_URL = "districts";

	function all() {

	}

	function getSchools(array $query = []){
		return $this->getObjects(static::CLEVER_SCHOOL, $query);
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

	function getStatus(array $query = []){
		return $this->getObjects(static::CLEVER_STATUS, $query);
	}

	function getAdmins(array $query = []) {
		return $this->getObjects(static::CLEVER_ADMIN, $query);
	}

	function getSchoolAdmins(array $query = []) {
		return $this->getObjects(static::CLEVER_SCHOOL_ADMIN, $query);
	}

}