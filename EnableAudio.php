<?php

class EnableAudio extends PluginAbstract
{
	/**
	 * @var string Name of plugin
	 */
	public $name = 'EnableAudio';

	/**
	 * @var string Description of plugin
	 */
	public $description = 'Add support for audio (i.e. mp3) files when uploading media. Based on work by Wes Wright.';

	/**
	 * @var string Name of plugin author
	 */
	public $author = 'Justin Henry';

	/**
	 * @var string URL to plugin's website
	 */
	public $url = 'https://uvm.edu/~jhenry/';

	/**
	 * @var string Current version of plugin
	 */
	public $version = '0.0.1';
	/**
	 * Performs install operations for plugin. Called when user clicks install
	 * plugin in admin panel.
	 *
	 */
	public function install()
	{ 
		$formats = array('mp3', 'm4a');
		Settings::set('enable_audio_formats', json_encode($formats));
		EnableAudio::appendAcceptedFormats();

		Filesystem::createDir(UPLOAD_PATH . '/mp3/');
	}
	/**
	 * Attaches plugin methods to hooks in code base
	 */
	public function load()
	{
		Plugin::attachEvent('app.start', array(__CLASS__, 'appendAcceptedFormats'));		

		// Starting at top of upload completion controller, 
		// b/c we still have a videoId in the session vars
		Plugin::attachEvent('upload_complete.start', array(__CLASS__, 'processAudio'));
	}

	/**
	 * Set the allowed file formats to include 
	 * 
	 */
	public static function appendAcceptedFormats()
	{
		$audioFormats = json_decode(Settings::get('enable_audio_formats'));
		$config = Registry::get('config');
		$formats = $config->acceptedVideoFormats;
		$newFormats = array_merge($formats, $audioFormats);
		$config->acceptedVideoFormats = $newFormats;
		$config->mp3Url = BASE_URL . '/cc-content/uploads/mp3';;
		Registry::set('config', $config);

	}
	/**
	 * Handle audio file uploads
	 * 
	 */
	public static function processAudio()
	{
      $video = EnableAudio::getVideoFromSession();
      $audioFormats = json_decode(Settings::get('enable_audio_formats'));
      if( in_array($video->originalExtension, $audioFormats) ) {
        EnableAudio::verifyAudioFile($video);
        if (strtolower($video->originalExtension) == 'mp3') {
          EnableAudio::processMp3($video);
        }
        if (strtolower($video->originalExtension) == 'm4a') {
          EnableAudio::copyThumb($video);
        }
      }
	}
	
	/**
	 * Get Video object based on uploaded video in _SESSION
	 * 
	 */
	private static function getVideoFromSession()
	{
		$videoMapper = new VideoMapper();

		if (isset($_SESSION['upload']->videoId)) {
			$video_id = $_SESSION['upload']->videoId;
			$video = $videoMapper->getVideoById($video_id);
			return $video;
		}
		
		return false;
	}


	/**
	 * Handle mp3 post-upload tasks.
	 * 
	 */
	public static function processMp3($video)
	{
		$mp3FilePath =  UPLOAD_PATH . '/mp3/' . $video->filename . '.mp3';
		$rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;

		EnableAudio::copyThumb($video);
		EnableAudio::moveAudioFile($rawVideo, $mp3FilePath, $video);
		EnableAudio::saveAudioInfo($rawVideo, $video);
		EnableAudio::cleanup($rawVideo, $video);

	}

	/**
	 * Copy the included thumbnails to the thumbs directory. 
	 * 
	 */
	public static function copyThumb($video)
	{	
		$thumbPath = UPLOAD_PATH . '/thumbs';
		$audioThumbJPG = dirname(__FILE__) . '/file-audio-regular.jpg'; 
		$jpgThumb = '/' . $video->filename .  '.jpg';
		EnableAudio::debugLogMessage("\nCopying thumbnail...");
		if (!copy($audioThumbJPG, $thumbPath . $jpgThumb)) {
			throw new Exception("error copying the JPG thumbnail file $audioThumbJPG to $thumbPath . The id of the video is $video->videoId, with title: $video->title");
		}

		$pngThumb = '/' . $video->filename .  '.png';
		$audioThumbPNG = dirname(__FILE__) . '/file-audio-regular.png';
		EnableAudio::debugLogMessage("\nCopying PNG thumbnail...");
		if (!copy($audioThumbPNG, $thumbPath . $pngThumb)) {
			throw new Exception("error copying the PNG thumbnail file $audioThumbPNG to $thumbPath . The id of the video is $video->videoId, with title: $video->title");
		}

	}
	/**
	 * Get the duration for an audio file. 
	 * 
	 */
	public static function getDuration($rawVideo, $video)
	{
		EnableAudio::debugLogMessage("\nRetrieving audio duration...");

		// Retrieve duration of raw video file.
		$ffmpegPath = Settings::get('ffmpeg');
		$durationCommand = "$ffmpegPath -i $rawVideo 2>&1 | grep Duration:";
		exec($durationCommand, $durationResults);

		$formatResults = print_r($durationResults, TRUE);
		EnableAudio::debugLogMessage( "Duration command results:\n" . $formatResults );

		$durationResultsCleaned = preg_replace('/^\s*Duration:\s*/', '', $durationResults[0]);
		preg_match ('/^[0-9]{2}:[0-9]{2}:[0-9]{2}/', $durationResultsCleaned, $duration);
		$sec = Functions::durationInSeconds($duration[0]);

		// Debug Log
		EnableAudio::debugLogMessage("Duration in Seconds: $sec");
		$formattedDuration = Functions::formatDuration($duration[0]);
		return $formattedDuration;
	}
	
	/**
	 * Move file to final destination.
	 * 
	 */
	public static function moveAudioFile($rawFilePath, $newPath, $video)
	{
		EnableAudio::debugLogMessage("\nCopying file...");
		if (!copy($rawFilePath,$newPath)) {
			throw new Exception("error copying The raw mp3 file $rawFilePath to $newPath . The id of the video is: $video->videoId $video->title");
		}
	
	}

	/**
	 * Update database with info on the new upload.
	 * 
	 */
	public static function saveAudioInfo($rawFilePath, $video)
	{
		EnableAudio::debugLogMessage("\nUpdating audio information...");

		// Update database with new video status information
		$videoMapper = new VideoMapper();
		$video->duration = EnableAudio::getDuration($rawFilePath, $video);
		$videoMapper->save($video);

		// Activate video
		$videoService = new VideoService();
		$videoService->approve($video, 'activate');
	
	}

	/**
	 * Clean up logs and temp/original uploads. 
	 * 
	 */
	public static function cleanup($rawVideo, $video)
	{
		$config = Registry::get('config');
		try {
			// Delete original video
			if (Settings::get('keep_original_video') != '1') {
				EnableAudio::debugLogMessage("\nDeleting raw audio...");
				Filesystem::delete($rawVideo);
			}

			// Delete encoding log files
			if ($config->debugConversion) {
				EnableAudio::debugLogMessage("\nAudio ID: $video->videoId $video->title, has completed processing!\n");
			} else {
				Filesystem::delete($debugLog);
			}
		} catch (Exception $e) {
			App::alert('Error During audio Encoding', $e->getMessage());
			App::log(CONVERSION_LOG, $e->getMessage());
		}
	}


	/**
	 * Confirm size and existence of uploaded media.
	 * 
	 */
	public static function verifyAudioFile($video)
	{
		$rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;
		EnableAudio::debugLogMessage('Verifying raw audio file exists...');

		if (!file_exists ($rawVideo)) {
			throw new Exception("The raw file $rawVideo does not exist. The id of the file is: $video->videoId $video->title");
		}

		EnableAudio::debugLogMessage('Verifying raw audio file upload was valid size...');

		// Greater than min. 5KB, anything smaller is probably corrupted
		if (!filesize ($rawVideo) > 1024*5) {
			throw new Exception("The raw audio file upload is not a valid filesize. The id of the video is: $video->videoId $video->title");
		}

	}

	/**
	 * Format and output log info for a simple string.
	 * 
	 * @param string $message log content
	 * 
	 */
	private static function debugLogMessage($message)
	{
		$config = Registry::get('config');
		$config->debugConversion ? App::log(CONVERSION_LOG, $message) : null;
	}
}


?>
