<?php
/**
 * Provides consistant mutex feature under Linux or Windows platforms.
 */
class Mutex {
	private $id;
	private $semId;
	private $isAcquired = false;
	private $compat = false;
	private $filename = '';
	private $fileHandle;

	function __construct() {
		if (!function_exists('sem_get')) {
			$this->compat = true;
		}
	}

	/**
	 * 
	 * @param long $id
	 * @param string $filename
	 */
	public function init($id, $filename = '') {
		$this->id = $id;

		if ($this->compat) {
			if (empty($filename)) {
				return false;
			} else {
				$this->filename = $filename;
			}
		} else {
			if (!($this->semId = sem_get($this->id, 1))) {
				return false;
			}
		}

		return true;
	}

	public function acquire() {
		if ($this->compat) {
			if (($this->fileHandle = @fopen($this->filename, "w+")) == false) {
				return false;
			}

			if (flock($this->fileHandle, LOCK_EX) == false) {
				return false;
			}
		} else {
			if (!sem_acquire($this->semId)) {
				return false;
			}
		}

		$this->isAcquired = true;
		return true;
	}

	public function release() {
		if (!$this->isAcquired) {
			return true;
		}

		if ($this->compat) {
			if (flock($this->fileHandle, LOCK_UN) == false) {
				return false;
			}

			fclose($this->fileHandle);
		} else {
			if (!sem_release($this->semId)) {
				return false;
			}
		}

		$this->isAcquired = false;
		return true;
	}

	public function getId() {
		return $this->semId;
	}
}
?>
