<?php
namespace FurMedia\Cache;
/** Recoverable maintenance using fixed roots, excluding symlinks and source entrypoints. */
class Management {
 public static function archive($kind,array $roots,$vault,$limit=500){
  if(!isset($roots[$kind])||!is_dir($roots[$kind])||is_link($roots[$kind])){throw new \InvalidArgumentException('Maintenance root unavailable');}$root=realpath($roots[$kind]);$dest=rtrim($vault,'/\\').'/'.gmdate('Ymd-His').'-'.bin2hex(Entropy::bytes(4)).'/'.$kind;
  if(!is_dir($dest)&&!mkdir($dest,0700,true)){throw new \RuntimeException('Archive unavailable');}$files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));$moved=0;
  foreach($files as $file){if($moved>=max(1,min(500,(int)$limit))){break;}if(!$file->isFile()||$file->isLink()){continue;}$path=$file->getRealPath();if(!$path||strpos($path,$root.DIRECTORY_SEPARATOR)!==0||in_array($file->getFilename(),array('index.html','index.php','.htaccess'),true)){continue;}if($kind==='native' && (strpos(str_replace('\\','/',$path),'/furmedia_cache')!==false || strpos(str_replace('\\','/',$path),'/skynova')!==false)){continue;}if($kind==='logs'&&strtolower($file->getExtension())!=='log'){continue;}$target=$dest.DIRECTORY_SEPARATOR.substr($path,strlen($root)+1);if(!is_dir(dirname($target))){mkdir(dirname($target),0700,true);}if(rename($path,$target)){$moved++;}}
  return array('moved'=>$moved,'archive'=>basename(dirname($dest)).'/'.$kind,'limit'=>500);
 }
}
