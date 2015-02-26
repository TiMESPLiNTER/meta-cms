<?php

/**
 * @author Pascal Muenst <entwicklung@metanet.ch>
 * @copyright Copyright (c) 2013, METANET AG
 * @version 1.0.0
 */

namespace ch\metanet\filemanager;

use ch\timesplinter\core\FrameworkLoggerFactory;
use ch\timesplinter\db\DB;

class FileHandler
{
	private $db;
	private $savePath;
	private $fileTypes;
	private $logger;

	public function __construct(DB $db, $savePath)
	{
		$this->logger = FrameworkLoggerFactory::getLogger($this);

		$this->db = $db;
		$this->savePath = $savePath;

		$this->fileTypes = array(
			'image' => array(
				'types' => array(
					'image/jpeg', 'image/jpx', 'image/jp2', 'image/jpm',
					'image/ief', 'image/png', 'image/gif', 'image/png',
					'image/svg+xml', 'image/tiff', 'image/tiff-x',
					'image/vnd.adobe.photoshop', 'image/x-icon'
				),
				'send' => 0
			),

			'audio' => array(
				'types' => array(
					'audio/ac3', 'audio/basic', 'audio/mid', 'audio/ogg',
					'audio/mpeg', 'audio/x-aiff', 'audio/x-mpegurl',
					'audio/x-aiff', 'audio/wav', 'audio/x-wav'
				),
				'send' => 1
			),

			'video' => array(
				'types' => array(
					'application/annodex', 'application/mp4',
					'application/ogg', 'application/vnd.rn-realmedia',
					'application/x-matroska', 'video/3gpp', 'video/3gpp2',
					'video/annodex', 'video/divx', 'video/flv',	'video/h264',
					'video/mp4', 'video/mp4v-es', 'video/mpeg', 'video/mpeg-2',
					'video/mpeg4', 'video/ogg', 'video/ogm', 'video/quicktime',
					'video/ty', 'video/vdo', 'video/vivo', 'video/vnd.rn-realvideo',
					'video/vnd.vivo', 'video/webm', 'video/x-bin', 'video/x-cdg',
					'video/x-divx', 'video/x-dv', 'video/x-flv', 'video/x-la-asf',
					'video/x-m4v', 'video/x-matroska',
					'video/x-motion-jpeg', 'video/x-ms-asf', 'video/x-ms-dvr',
					'video/x-ms-wm', 'video/x-ms-wmv', 'video/x-msvideo',
					'video/x-sgi-movie', 'video/x-tivo', 'video/avi', 'video/x-ms-asx',
					'video/x-ms-wvx', 'video/x-ms-wmx'
				),
				'send' => 1
			),

			'doc' => array(
				'types' => array(
					'application/msword',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
					'application/vnd.ms-excel',
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'application/vnd.ms-powerpoint',
					'application/vnd.openxmlformats-officedocument.presentationml.presentation',
					'application/pdf',
					'application/vnd.oasis.opendocument.text',
					'application/vnd.oasis.opendocument.text-master',
					'application/vnd.oasis.opendocument.spreadsheet',
				),
				'send' => 1
			)
		);
	}

	public function getAllFiles($order, $keyword = null, $categories = null)
	{
		$condStr = array();
		$params = array();

		if($keyword !== null) {
			$condStr[] = 'filename LIKE ?';
			$params[] = '%' . $keyword . '%';
		}

		if($categories !== null) {
			$condStr[] = 'category IN(' . DB::createInQuery($categories) . ')';
			$params = array_merge($params, $categories);
		}
		
		$stmntAllFiles = $this->db->prepare("
			SELECT ID, filenamesys, filename, filetype, filesize, send, otherinfo
			FROM file
			" . ( count($condStr) > 0 ? ' WHERE ' . implode(' AND ', $condStr) : null) . "
			ORDER BY " . $order['column'] . " " . $order['sort']
		);

		$tmpArr = $this->db->select($stmntAllFiles, $params);

		foreach($tmpArr as $f) {
			$f->filepath = $this->savePath . $f->filenamesys . DIRECTORY_SEPARATOR . $f->filename;
		}

		return $tmpArr;
	}

	public function getFileByID($fileID) {
		$stmntFile = $this->db->prepare("
			SELECT ID, filenamesys, filename, filetype, filesize, send, otherinfo, category
			FROM file
			WHERE ID = ?
		");

		$resFile = $this->db->select($stmntFile, array($fileID));

		if(count($resFile) <= 0)
			return null;

		$resFile[0]->filepath = $this->savePath . $resFile[0]->filenamesys . DIRECTORY_SEPARATOR  . $resFile[0]->filename;

		return $resFile[0];
	}

	public function storeFile($fileInfo) {
		$stmntInsertFile = $this->db->prepare("
			INSERT INTO file SET filenamesys = ?, filename = ?, filetype = ?, filesize = ?, send = ?, otherinfo = ?, category = ?
		");

		$fileNameSys = uniqid();
		$subDir = isset($fileInfo['collection'])?$fileInfo['collection']:$this->getDirForMimeType($fileInfo['type']);

		if(is_dir($this->savePath . $subDir) === false)
			mkdir($this->savePath . $subDir);

		if(isset($fileInfo['tmp_name']) === true) {
			$toPath = $this->savePath . $subDir . DIRECTORY_SEPARATOR . $fileNameSys;

			if(is_uploaded_file($fileInfo['tmp_name']) === true) {
				$resMove = move_uploaded_file($fileInfo['tmp_name'], $toPath);
			} else {
				$resMove = copy($fileInfo['tmp_name'], $toPath);
				unlink($fileInfo['tmp_name']);
			}

			if($resMove === false) {
				throw new \RuntimeException('Could not move file from ' . $fileInfo['tmp_name'] . ' to ' . $toPath);
			}
		}

		$otherInfo = null;

		if($this->hasFileType($fileInfo['type'], 'image')) {
			$imgInfo = getimagesize($this->savePath . $subDir . DIRECTORY_SEPARATOR . $fileNameSys);
			$otherInfo = $imgInfo[0] . ';' . $imgInfo[1];
		}

		$fileID = $this->db->insert($stmntInsertFile, array(
			$fileNameSys,
			$fileInfo['name'],
			$fileInfo['type'],
			$fileInfo['size'],
			isset($this->fileTypes[$subDir])?$this->fileTypes[$subDir]['send']:1,
			$otherInfo,
			$subDir
		));

		return $fileID;
	}

	public function deleteFile($fileID) {
		try {
			$stmntFile = $this->db->prepare("SELECT filenamesys, category FROM file WHERE ID = ?");
			$resFile = $this->db->select($stmntFile, array($fileID));

			if(count($resFile) <= 0)
				return false;

			$filePath = $this->savePath . $resFile[0]->category . DIRECTORY_SEPARATOR . $resFile[0]->filenamesys;

			if(file_exists($filePath) === true)
				unlink($filePath);

			$stmntDelete = $this->db->prepare("
				DELETE FROM file WHERE ID = ?
			");

			$affectedRows = $this->db->delete($stmntDelete, array($fileID));

			return ($affectedRows > 0);
		} catch(\Exception $e) {
			$this->logger->error('Could not delete file', $e);
			return false;
		}
	}

	public function getDirForMimeType($mimeType)
	{
		foreach($this->fileTypes as $dir => $ft) {
			if(in_array($mimeType, $ft['types']))
				return $dir;
		}

		return 'other';
	}

	public function hasFileType($mimeType, $expectedFileType)
	{
		foreach($this->fileTypes as $ft => $data) {
			if(in_array($mimeType, $data['types']) && $expectedFileType === $ft)
				return true;
		}

		return false;
	}

	public function getFileTypes()
	{
		return $this->fileTypes;
	}
}

/* EOF */