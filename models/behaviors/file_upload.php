<?php
/**
 * Generic File Upload Behavior
 *
 * This behavior is meant to be used and should be used by any other behavior
 * that is going to do anyting with file uploads.
 *
 * @todo replace $this->__file with $this->runtime[$Model->alias]['callBack']
 */
class FileUploadBehavior extends ModelBehavior {
/**
 * Settings array
 *
 * @var array
 * @access public
 */
	public $settings = array();
/**
 * Default settings array
 *
 * - fileField (string, default file)
 *   The post field that holds the uploaded file info
 *
 * - basePath (string, default null)
 *   If empty the basePath is APP/tmp/ModelAlias/
 *   Notice that it must end with a /
 *
 * - randomPath (bool, default false)
 *   If the to true it generates 3 level deep semi random path to avoid
 *   file system performance problems. Please check _randomPath() for more information
 *
 * - storeFileAsId (bool, default false)
 *   If set to true the id is used as filename
 *
 * - combinePathAndFile (bool, default false)
 *   if true path and filename are stored in one field
 *   if not two fields in the db table are used for filename and path
 *   you can define them using dirField and filenameField, they have default names
 *
 * - enableHash (bool, default false)
 *   If set to true an md5 hash of the file is generated
 *
 * - hashField (string, default hash)
 *   If a hash is generated its stored in the field
 *
 * - validate (bool, default true)
 *   Controlls if mime and file extension should be validated
 *
 * - allowedMime (array, default array(*), all)
 *   An array of allowed mime types
 *
 * - mimeField (string, default mime_type)
 *   Field to store the mime type
 *
 * - sizeField (string, default size)
 *   holds the name of the table field that stores the size value
 *
 * - beautifyFilename (bool)
 *   Removes white spaces from file name and replaces them by _
 *
 * - extensionMapping (array)
 *   Provide list of extensions, used for change file extension by map of one extension to another
 *
 * - internalCall (bool)
 *   Turn on internal file storage
 *
 * @var array
 * @access protected
 */
	protected $_defaults = array(
		'basePath' => null, // If empty the basePath is set to APP/tmp/ModelAlias/ by default
		'randomPath' => true, // basePath.##.##.##
		'extBasePath' => array(), // array('jpg' => 'media' . DS . 'images' . DS . 'jpg') to build the path based on extension
		'storeFileAsId' => false, // filename == id
		'combinePathAndFile' => false, // dirField => path.file
		'dirField' => 'path',
		'filenameField' => 'filename',
		'fileField' => 'file',
		'sizeField' => 'filesize',
		'hashField' => 'hash',
		'mimeField' => 'mime_type',
		'enableHash' => false,
		'validate' => true,
		'allowedMime' => array('*'), //array('image/jpeg', 'image/pjpeg', 'image/gif', 'image/png'),
		//'allowedExt' => array('*'), //array('jpg','jpeg','gif','png'),
		'beautifyFilename' => false,
		'internalCall' => false,
		'mimeDatabase' => null,
		'extensionMapping' => array());

/**
 * Error message
 *
 * If something fails this is populated with an errormsg that can be passed to the view
 *
 * @var string
 * @access public
 */
	public $uploadError = null;

/**
 * File resource
 *
 * Used to collect informations about the file to carry it trough the whole
 * process and to have them accessible after saving/deleting to handle saving/deletion of the file data.
 *
 * @var array
 * @access protected
 */
	protected $__file = array();

/**
 * after delete callback
 *
 * @param AppModel $Model
 * @return boolean
 * @access public
 */
	public function afterDelete(Model $Model) {
		return $this->_delete($Model);
	}

/**
 * after save callback
 *
 * @param AppModel $Model
 * @return void
 * @access public
 */
	public function afterSave(Model $Model) {
		if (empty($this->__file)) {
			return;
		}

		if ($this->_saveFile($Model)) {
			$id = $Model->id;
			$data = $Model->data;
			if (!empty($this->settings[$Model->alias]['oldFileId'])) {
				$Model->delete($this->settings[$Model->alias]['oldFileId']);
			}
			$Model->id = $id;
			$Model->data = $data;
		}
	}

/**
 * before delete callback
 *
 * Note that it is not required to set the id, because it is already set
 * trough the Model::delete() method.
 *
 * @param AppModel $Model
 * @access public
 */
	public function beforeDelete(Model $Model) {
		$Model->recursive = -1;
		$row = $Model->read();
		$this->__file = $row[$Model->alias];
	}

/**
 * Before save callback
 *
 * Collecting and generating meta data in here because we only want to
 * move the file after the meta data was saved. File data is stored in
 * $this->__file to make sure it is aviliable after save.
 *
 * @todo make it possible to use fileField also as fielenameField
 *
 * @param AppModel $Model
 * @access public
 */
	public function beforeSave(Model $Model) {
		if (empty($Model->data[$Model->alias][$this->settings[$Model->alias]['fileField']])) {
			return;
		}

		extract($this->settings[$Model->alias]);

		$modelField = $Model->data[$Model->alias][$fileField];
		$ext = $this->mimeType($Model, $modelField['tmp_name'], array(), $modelField['name']);
		$this->transformExtension($Model, $ext);

		$Model->data[$Model->alias][$mimeField] = $ext;
		if (isset($extBasePath[$ext])) {
			$basePath = $extBasePath[$ext];
		} else {
			$basePath = $extBasePath['default'];
		}

		$this->_checkBasePath($basePath);

		if ($beautifyFilename === true) {
			$Model->data[$Model->alias][$fileField]['name'] = $this->_beautify($modelField['name']);
		}

		$Model->data[$Model->alias][$filenameField] = $modelField['name'];
		$Model->data[$Model->alias][$sizeField] = $modelField['size'];
		$Model->data[$Model->alias][$dirField] = $basePath;
		if (!empty($enableHash) && is_string($enableHash)) {
			$Model->data[$Model->alias][$hashField] = $this->fileHash($Model, $modelField['tmp_name'], $enableHash);
		}

		if ($combinePathAndFile === true) {
			$Model->data[$Model->alias][$dirField] = $basePath . $modelField['name'];
		}

		$this->__file['filenameField'] = $modelField['name'];
		$this->__file['tmp_name'] = $modelField['tmp_name'];
		$this->__file['name'] = $modelField['name'];
		unset($Model->data[$Model->alias][$fileField]);
	}

/**
 * Before validation callback
 *
 * Check if the file is really an uploaded file
 * and run custom checks for file extensions and / or mime type if configured to do so.
 *
 * @param AppModel $Model
 * @access public
 */
	public function beforeValidate(Model $Model) {
		extract($this->settings[$Model->alias]);
		if ($validate === true && isset($Model->data[$Model->alias][$fileField]) && is_array($Model->data[$Model->alias][$fileField])) {
			if ($this->validateUploadError($Model, $Model->data[$Model->alias][$fileField]['error']) === false) {
				$Model->validationErrors[$fileField] = $this->uploadError;
				return false;
			}

			if (!empty($Model->data[$Model->alias][$fileField])) {
				if (empty($internalCall) && !is_uploaded_file($Model->data[$Model->alias][$fileField]['tmp_name'])) {
					$this->uploadError = __('The uploaded file is no valid upload.', true);
					$Model->invalidate($fileField, $this->uploadError);
					return false;
				}
			}

			if (!$this->mimeType($Model, $Model->data[$Model->alias][$fileField]['tmp_name'], $allowedMime, $Model->data[$Model->alias][$fileField]['name'])) {
				$this->uploadError = __('You are not allowed to upload files of this type.', true);
				$Model->invalidate($fileField, $this->uploadError);
				return false;
			}
		}
		return true;
	}

/**
 * This method checks if the basepath directory exists and is writeable, if not,
 * it tries to create it
 *
 * @param object Model instace
 * @return void
 * @access public
 */
	private function _checkBasePath($basePath) {
		if (!is_dir($basePath)) {
			if (!class_exists('folder')) {
				App::import('Core', 'Folder');
			}
			$Folder = new Folder();

			if(!$Folder->create($this->__trimPath($basePath))) {
				throw new Exception(sprintf(__('Unable to create directory %s for file uploads.', true),
					$basePath));
			}
		}

		if (!is_writable($basePath)) {
			if (!class_exists('folder')) {
				App::import('Core', 'Folder');
			}

			$Folder = new Folder();
			if (!$Folder->chmod($basePath, 0775)) {
				throw new Exception(sprintf(__('Unable to write files into directory %s for file uploads.', true),
				$basePath));
			}
		}
	}

/**
 * Valdates the error value that comes with the file input file
 *
 * @param object Model instance
 * @param integer Error value from the form input [file_field][error]
 * @return boolean True on success, if false the error message is set to the models field and also set in $this->uploadError
 * @access public
 */
	public function validateUploadError(Model $Model, $error = null) {
		if (!is_null($error)) {
			switch ($error) {
				case UPLOAD_ERR_OK:
					return true;
				break;
				case UPLOAD_ERR_INI_SIZE:
					$this->uploadError = __('The uploaded file exceeds limit of ('.ini_get('upload_max_filesize').').', true);
				break;
				case UPLOAD_ERR_FORM_SIZE:
					$this->uploadError = __('The uploaded file is to big, please choose a smaller file or try to compress it.', true);
				break;
				case UPLOAD_ERR_PARTIAL:
					$this->uploadError = __('The uploaded file was only partially uploaded.', true);
				break;
				case UPLOAD_ERR_NO_FILE:
					$this->uploadError = __('No file was uploaded.', true);
				break;
				case UPLOAD_ERR_NO_TMP_DIR:
					$this->uploadError = __('The remote server has no temporary folder for file uploads. Please contact the site admin.', true);
				break;
				case UPLOAD_ERR_CANT_WRITE:
					$this->uploadError = __('Failed to write file to disk. Please contact the site admin.', true);
				break;
				case UPLOAD_ERR_EXTENSION:
					$this->uploadError = __('File upload stopped by extension. Please contact the site admin.', true);
				break;
				default:
					$this->uploadError = __('Unknown File Error. Please contact the site admin.', true);
				break;
			}
			return false;
		}
		return true;
	}

/**
 * Return file extension
 *
 * @param string
 * @return boolean string or false
 */
	public function getFileExtension($name = null) {
		$list = explode('.', $name);
		if (count($list) > 1) {
			$ext = $list[count($list)-1];
			return $ext;
		}
		return false;
	}

/**
 * Check mime type type, works with path and uploaded file
 * If last parameter name defined return extension of filename instead
 *
 * Please note that, if the standard method for php4/5 is used
 * http://www.php.net/manual/en/function.mime-content-type.php
 * the results _could be wrong_! Check the comments there.
 *
 * You can NEVER be sure that the mime type is 100% correct!
 * Use it with care, don't depend on it!
 *
 * @param AppModel $Model
 * @param string $file File to check
 * @param array $mimeTypes mime types to follow
 * @param string
 * @return mixed returns bool if 2nd param is used
 * @access public
 */
	public function mimeType(Model $Model, $file, $mimeTypes = array(), $name = '') {
		if (!is_file($file)) {
			return false;
		}

		if (!empty($name)) {
			$mimeType = $this->getFileExtension(strtolower($name));
		} else {
			if (function_exists('finfo_open')) {
				if (!empty($this->settings[$Model->alias]['mimeDatabase'])) {
					$finfo = finfo_open(FILEINFO_MIME, $this->settings[$Model->alias]['mimeDatabase']);
				} else {
					$finfo = finfo_open(FILEINFO_MIME);
				}
				$mimeType = finfo_file($finfo, $file);
				finfo_close($finfo);
			} else {
				$mimeType = mime_content_type($file);
			}
		}

		if (!empty($mimeTypes)) {
			return (in_array($mimeType, $mimeTypes) || in_array('*', $mimeTypes));
		} else {
			return $mimeType;
		}
	}

/**
 * Behavior setup
 *
 * Merge settings with default config, then it is checking if the target directory
 * exists and if it is writeable. It will throw an error if one of both fails.
 *
 * @param AppModel $Model
 * @param array $settings
 * @access public
 */
	public function setup(Model $Model, $settings = array()) {
		if (!is_array($settings)) {
			throw new InvalidArgumentException(__('Settings must be passed as array!', true));
		}

		if (!isset($this->settings[$Model->alias])) {
			$this->settings[$Model->alias] = $this->_defaults;
		}

		$this->settings[$Model->alias] = array_merge($this->settings[$Model->alias], $settings);

		if (empty($this->settings[$Model->alias]['basePath'])) {
			$this->settings[$Model->alias]['basePath'] = APP . 'tmp' . DS . strtolower($Model->alias) . DS;
		}

		if (!isset($this->settings[$Model->alias]['extBasePath']['default'])) {
			$this->settings[$Model->alias]['extBasePath']['default'] = $this->settings[$Model->alias]['basePath'];
		}

		if (empty($this->settings[$Model->alias]['extensionMapping'])) {
			$this->settings[$Model->alias]['extensionMapping'] = array();
		}
	}

/**
 * Transforms the extension of a file
 *
 * @param AppModel $Model
 * @return string
 * @access public
 */
	public function transformExtension(Model $Model, &$extension) {
		$map = $this->settings[$Model->alias]['extensionMapping'];
		if (isset($map[$extension])) {
			$extension = $map[$extension];
		}
		return $extension;
	}

/**
 * This function will calculate CRC32 values that are compatible with the CRCs in the well known SFV files
 *
 * php's crc32 doesn't work out of the box, since the crc32 hash algo calculates
 * a different value than found in SFV files (probably a different poly is
 * used). There is another hash algo supported by PHP though, crc32b, which is
 * almost right, but needs some transformation. The transformation involves a
 * signed-unsigned conversion and a byte order (or endian) swap.
 *
 * @see http://www.php.net/manual/en/function.crc32.php#83691
 * @param string path and filename
 * @return string
 * @access public
 */
	public function crc32sfv($file = null) {
		$crc = hash_file("crc32b", $file);
		$crc = sprintf("%08x", 0x100000000 + hexdec($crc));
		return substr($crc, 6, 2) . substr($crc, 4, 2) . substr($crc, 2, 2) . substr($crc, 0, 2);
	}

/**
 * Gets a hash for a file based on its content
 *
 * @throws InvalidArgumentException if the hash type is invalid
 * @param object Model instance
 * @param path and filename
 * @param hash algo to use, sha1, md5, crc32sfv (sfv compatible output) or model method
 * @return string
 * @access public
 */
	public function fileHash(Model $Model, $file = null, $hashType = 'sha1') {
		$hash = null;
		if ($hashType == 'sha1') {
			$hash = sha1_file($file);
		} elseif ($hashType == 'md5') {
			$hash = md5_file($file);
		} elseif ($hashType == 'crc32sfv') {
			$hash = $this->crc32sfv($file);
		} elseif (method_exists($Model, $hashType)) {
			$hash = $Model->$hashType($file);
		} else {
			throw new InvalidArgumentException(sprintf(
				__d('media', 'Invalid hash type "%1$s". Use sha1, md5, crc32sfv or create a %1$s method in the %2$s model.', true),
				$hashType,
				$Model->alias));
		}

		return $hash;
	}

/**
 * after save file callback for behaviors extending this class
 *
 * @param AppModel $Model
 * @access protected
 */
	protected function _afterSaveFile(Model $Model) {
		$Model->Behaviors->enable('FileUpload');
		return true;
	}

/*
 * Makes a filename looking nice
 *
 * Replaces spaces by default with underscore
 *
 * @param string $string
 * @param array $replacements
 * @access protected
 */
	protected function _beautify($string, $replacements = array(' ', '_')) {
		$string = preg_replace(array('/[^\w\s]/', '/\\s+/') , $replacements, $string);
		return $string;
	}

/*
 * before save file callback for behaviors extending this class
 *
 * @param AppModel $Model
 * @access protected
 */
	protected function _beforeSaveFile(Model $Model) {
		$Model->Behaviors->disable('FileUpload');
		return true;
	}

/**
 * Builds a semi random path based on the id to avoid having thousands of files
 * or directories in one directory. This would result in a slowdown on most file systems.
 *
 * Works up to 5 level deep
 *
 * @param mixed $string
 * @param integer $level
 * @return mixed
 * @access protected
 */
	protected function _randomPath($string, $level = 3) {
		if (!$string) {
			throw new InvalidArgumentException(__('First argument is not a string!', true));
		}
		$string = crc32($string);

		$decrement = 0;
		$path = null;
		for ($i = 0; $i < $level; $i++) {
			$decrement = $decrement -2;
			$path .=  sprintf("%02d" . DS, substr('000000' . $string, $decrement, 2));
		}
		return $path;
	}

/**
 * Moves the temporary file to its target destination
 *
 * @param AppModel $Model
 * @return boolean
 * @access protected
 */
	protected function _moveTmpFile(Model $Model) {
		extract($this->settings[$Model->alias]);
		//debug($this->__file);debug($this->settings[$Model->alias]);
		if ($internalCall) {
			@rename($this->__file['tmp_name'], $this->__file['path'] . $this->__file['filenameField']);
			return true;
		} else {
			return move_uploaded_file($this->__file['tmp_name'], $this->__file['path'] . $this->__file['filenameField']);
		}
	}

/**
 * Processes a file after it was uploaded
 *
 * If the file can't saved for some reason, the previous saved meta data will
 * be removed from the database table.
 *
 * @param AppModel $Model
 * @access protected
 */
	protected function _saveFile(Model $Model) {
		extract($this->settings[$Model->alias]);
		$ext = $Model->data[$Model->alias][$mimeField];

		if (isset($extBasePath[$ext])) {
			$basePath = $extBasePath[$ext] . DS;
		} else {
			$basePath = $extBasePath['default'] . DS;
		}

		$path = $basePath;
		if ($randomPath === true) {
			$path .= $this->_randomPath($Model->id);
		}

		if ($storeFileAsId === true) {
			$name = str_replace('-', '', $Model->id);
			$this->__file['filenameField'] = $name;
		}

		if ($combinePathAndFile === true) {
			$Model->data[$Model->alias][$dirField] = $path . $this->__file['filenameField'];
		}
		$this->__file['path'] = $path;

		if (!$this->_beforeSaveFile($Model)) {
			return false;
		}

		if (!is_dir($this->__file['path'])) {
			if (!class_exists('folder')) {
				App::import('Core', 'Folder');
			}
			$Folder = new Folder();
			if (!$Folder->create($this->__trimPath($this->__file['path']))) {
				$Model->delete($Model->id);
				throw new Exception(sprintf(__('Unable to create directory %s for file uploads.', true),
					$this->__file['path']));
				return false;
			}
		}

		if (!$this->_moveTmpFile($Model)) {
			$Model->delete($Model->id);
			throw new Exception(sprintf(__('Unable to move uploaded file %s to destination %s.', true),
				$this->__file['tmp_name'], $basePath));
			return false;
		}

		// Saving data to the model that could be built only after the entry was already saved.
		$path = str_replace($basePath, '', $path);
		$Model->data[$Model->alias][$dirField] = $path;
		if ($Model->save()) {
			$Model->read(null, $Model->id);
		} else {
			return false;
		}

		return $this->_afterSaveFile($Model);
	}

/**
 * Protected delete method for the file
 *
 * @param AppModel $Model
 * @param string $prefix
 * @access private
 */
	protected function _delete(Model $Model, $prefix = '') {
		extract($this->settings[$Model->alias]);

		if (!class_exists('file')) {
			App::import('Core', 'File');
		}

		$ext = $Model->data[$Model->alias][$mimeField];
		if (isset($extBasePath[$ext])) {
			$path = $extBasePath[$this->__file[$mimeField]] . DS;
		} else {
			$path = $extBasePath['default'] . DS;
		}

			$path .= $this->__file[$dirField];
		if ($combinePathAndFile === true) {
		} elseif ($randomPath === true) {
			$path .= $this->__file[$dirField] . $prefix . str_replace('-', '', $this->__file['id']);
		} else {
			$path .= $this->__file[$dirField] . $prefix . $this->__file[$filenameField];
		}

		$File = new File($path);
		if (!$File->exists()) {
			return true;
		}

		if (!$File->delete($path)) {
			throw new Exception(sprintf(__('Unable to remove file %s.', true), $path));
			return false;
		}
		return true;
	}

/**
 * Trims the path
 *
 * @param AppModel $Model
 * @return string Path
 * @access private
 */
	private function __trimPath($path) {
		$len = strlen($path);
		if ($path[$len - 1] == DS) {
			$path = substr($path, 0, $len - 1);
		}
		return $path;
	}

/**
 * Returns the latest error message
 *
 * @param AppModel $Model
 * @return string
 * @access public
 */
	public function getUploadError(Model $Model) {
		return $this->uploadError;
	}

}
?>