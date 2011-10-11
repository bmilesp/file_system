<?php

class FileSystem extends FileSystemAppModel{
	
	public $useTable = 'file_system';
	public $actsAs = array(
		'Containable',
	);
	
	
	public function __construct( $id = false, $table = null, $ds = null ){
		parent::__construct($id, $table, $ds);
		$this->Behaviors->attach('FileSystem.FileUpload', array(
				'randomPath' => false,
				'basePath' => IMAGES.$this->alias,
				'storeFileAsId' => false,
				'beautifyFilename' => true)
		);
	}
}
