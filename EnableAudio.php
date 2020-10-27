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
  public $version = '0.5.0';

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
      EnableAudio::copyThumb($video);
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
