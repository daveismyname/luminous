<?php

require_once(dirname(__FILE__) . '/cache.class.php');
require_once(dirname(__FILE__) . '/scanners.class.php');
require_once(dirname(__FILE__) . '/formatters/formatter.class.php');

require_once(dirname(__FILE__) . '/core/scanner.class.php');



// This is kind of a pseudo-UI class. It's a singleton which will be
// manipulated by a few procudural functions, for ease of use.
class _Luminous {
  public $version = 'master';
  public $settings = array(
    'cache-age' => -1,
    'wrap-width' => -1,
    'line-numbers' => true,
    'auto-link' => true,
    'max-height' => 500,
    'format' => 'html',
    'theme' => 'luminous_light',
    'html-strict' => false
  );

  public $scanners;

  

  public function __construct() {
    $this->scanners = new LuminousScanners();

    $this->register_default_scanners();
    

  }


  private function register_default_scanners() {
    // we should probably hide this in an include for neatness
    // when it starts growing.
    $language_dir = Luminous::root() . '/languages/';
    
    $this->scanners->AddScanner(array('c', 'cpp', 'h', 'hpp', 'cxx', 'hxx'),
      'LuminousCppScanner', 'C/C++', "$language_dir/cpp.php");
      
    $this->scanners->AddScanner('css',
      'LuminousCSSScanner', 'CSS', "$language_dir/css.php");
      
    $this->scanners->AddScanner(array('html', 'htm', 'xml'),
      'LuminousHTMLScanner', 'HTML/XML', "$language_dir/html.php",
      array('js', 'css'));
      
    $this->scanners->AddScanner('js',
      'LuminousJSScanner', 'JavaScript', "$language_dir/js.php");
      
    $this->scanners->AddScanner('php',
      'LuminousPHPScanner', 'PHP', "$language_dir/php.php",
      array('html'));
      
    $this->scanners->AddScanner(array('python', 'py'),
      'LuminousPythonScanner', 'Python', "$language_dir/python.php");
  }

  function get_formatter() {
    $fmt_path = dirname(__FILE__) . '/formatters/';

    switch(strtolower($this->settings['format'])) {
      case 'html' :
        require_once($fmt_path . 'htmlformatter.class.php');
        return new LuminousFormatterHTML();
      case 'html_inline':
        require_once($fmt_path . 'htmlformatter.class.php');
        return new LuminousFormatterHTMLInline();
      case 'latex':
        require_once($fmt_path . 'latexformatter.class.php');
        return new LuminousFormatterLatex();
      case null:
      case 'none':
        require_once($fmt_path . 'identityformatter.class.php');
         return new LuminousIdentityFormatter();
      default:
        throw new Exception('Unknown formatter: ' . $LUMINOUS_OUTPUT_FORMAT);
        return null;
    }
  }

  private function set_formatter_options(&$formatter) {
    $formatter->wrap_length = $this->settings['wrap-width'];
    $formatter->line_numbers = $this->settings['line-numbers'];
    $formatter->link = $this->settings['auto-link'];
    $formatter->height = $this->settings['max-height'];
    $formatter->strict_standards =$this->settings['html-strict'];
  }

  private function cache_id($scanner, $source) {
    $settings = $this->settings;
    ksort($settings);
    $id = md5($source);
    $id = md5($id . serialize($scanner));
    $id = md5($id . serialize($settings));
    return $id;
  }

  

  function highlight($scanner, $source, $use_cache=true) {
    
    $cache_obj = null;
    $out = null;
    if ($use_cache) {
      $cache_id = $this->cache_id($scanner, $source);
      $cache_obj = new LuminousCache($cache_id);
      $cache_obj->purge_older_than = $this->settings['cache-age'];
      $cache_obj->purge();
      $out = $cache_obj->read();
    }
    if ($out === null) {
      if (!($scanner instanceof LuminousScanner)) {
        $code = $scanner;
        $scanner = $this->scanners->GetScanner($code);
        if ($scanner === false) throw new Exception("No known scanner for '$code'");
      }
      
      $out_raw = $scanner->highlight($source);
      $formatter = $this->get_formatter();
      $this->set_formatter_options($formatter);
      $out = $formatter->Format($out_raw);
    }

    if ($use_cache) {
      $cache_obj->write($out);
    }
    return $out;
  }
}


// Here's our singleton.

$luminous_ = new _Luminous();


// here's our 'real' UI class, which uses the above singleton. This is all
// static because these are actually procudural functions, we're using the
// class as a namespace.
abstract class Luminous {


  static function highlight($scanner, $source, $cache=true) {
    global $luminous_;
    return $luminous_->highlight($scanner, $source, $cache);
  }

  static function highlight_file($scanner, $file, $cache=true) {
    return self::highlight($scanner, file_get_contents($file), $cache);
  }

  static function register_scanner($language_code, $classname, $readable_language,
    $path) {
      global $luminous_;
      $luminous_->scanners->AddScanner($language_code, $classname,
        $readable_language, $path);
  }

  static function root() {
    return realpath(dirname(__FILE__) . '/../');
  }

  static function get_themes() {
    $themes_uri = self::luminous_root() . "/style/";
    $themes = array();
    foreach(glob($themes_uri . "/*.css") as $css) {
      $fn = trim(preg_replace("%.*/%", '', $css));
      switch($fn) {
        // these are special, exclude these
        case 'luminous.css':
        case 'luminous_print.css':
        case 'luminous.min.css':
          continue;
        default:
        $themes[] = $fn;
      }
    }
    return $themes;
  }

  static function theme_exists($theme) {
    return in_array($theme, self::get_themes());
  }
  
  static function get_theme($theme) {
    if (self::theme_exists($theme)) 
      return file_get_contents(self::luminous_root() . "/style/" . $theme);
    else
      throw new Exception('No such theme file: ' . $theme);
  }


  

  static function setting($option) {
    global $luminous_;    
    if (!array_key_exists($option, $luminous_->settings))
      throw new Exception("Luminous: No such option: $option");
    return $luminous_->settings[$option];
  }

  static function set($option, $value) {
    global $luminous_;
    if (!array_key_exists($option, $luminous_->settings))
      throw new Exception("Luminous: No such option: $option");
    else $luminous_->settings[$option] = $value;
  }

  


  static function get_scanners() {
    global $luminous_;
    return $luminous_->scanners->ListScanners();
  }
}







