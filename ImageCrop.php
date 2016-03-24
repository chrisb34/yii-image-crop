<?php

namespace common\components;

use Yii;
use yii\helpers\Html;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Imagine\Image\ManipulatorInterface;

/**
 * This started out as a helper class to himiklab EasyThumbnail
 * but has turned out to be a complete clone and re-write
 * 
 * Added functions
 * - if image file is not found, display standard "not found" image
 * - resize and cache image
 * - crop image as required, square from rectangular
 * 
 * @author Chris Backhouse
 * @email chrisb@sudwebdesign.com
 */
class ImageCrop extends \yii\base\Component
{
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;

    /** @var string $cacheAlias path alias relative with @web where the cache files are kept */
    const CACHE_ALIAS = '@files';
    const CACHE_URL = '@filesUrl';

    /** @var int $cacheExpire */
    public static $cacheExpire = 0;

    const DEFAULT_IMAGE = '/images/no-photo.jpg';

    public static function cropImage($imagePath, $width, $height, $options, $thumbnail = false, $generate = true)
    {
        try
        {
            //Yii::info( ,"CROPIMAGE filename");
            ini_set('memory_limit', '1024M');
            
            // clean path
            $imagePath = urldecode($imagePath);
            
            $log = print_r($options, true);

            if (!is_file($imagePath) || !in_array( strtolower(pathinfo ( $imagePath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'gif', 'png', 'bmp', 'svg']) ) { 
                 $log .= ('Image doesnt exist: '.$imagePath.'\n');
                 //return Html::img(self::DEFAULT_IMAGE, array_merge($options, ['data-log' => (YII_DEBUG) ? $log : '']));
                 $imagePath = Yii::getAlias('@webroot/'.self::DEFAULT_IMAGE);
            }

            $filename = self::getCacheFile($imagePath, $width, $height, self::THUMBNAIL_OUTBOUND);
            if (is_file($filename)) {
                    $log .= ('Using exisitng thumb:'.$filename.'\n');
                    return self::thumbnailImg($filename, $width, $height, self::THUMBNAIL_OUTBOUND, array_merge($options, ['data-log' => (YII_DEBUG) ? $log : '']));
            }

            if ($generate)
            {
                $log .= ('Building thumb for:'.$filename.'\n');

                $imagine = Image::getImagine(['driver' => 'imagick']);
                $image = $imagine->open($imagePath);
                $size = $image->getSize();

                $log .= ( "IMAGE SIZES: ".$imagePath." - ".$size->getWidth()." x ".$size->getHeight()."\n");

                $largest = ($size->getWidth() > $size->getHeight()) ? $size->getWidth() : $size->getHeight();
                $format = ($size->getWidth() > $size->getHeight()) ? 'l' : 'p';
                $smallest = ($size->getWidth() < $size->getHeight()) ? $size->getWidth() : $size->getHeight();

                $ratio = $smallest / $largest;

                // landscape
                if ($format == 'l')
                {
                    $log .= ( "landscape\n");
                    // if bigger - resize

                    if  ($size->getWidth() > $width || $size->getHeight() > $height)
                    {
                        $log .= ( "width({$size->getWidth()}) or height({$size->getHeight()}) is greater than limit so resize using Image::thumbnail to $width x $height\n");
                        Image::thumbnail($imagePath, $width, $height, self::THUMBNAIL_OUTBOUND)
                            ->save($filename) ;

                    } 
                    elseif ($size->getWidth() < $width) 
                        { // this image is smaller should we blow it up or stick it on a background ???
                        $log .= ( "It's smaller so we should try and center it \n");
                        Image::thumbnail($imagePath, $width, $height, self::THUMBNAIL_OUTBOUND)
                            ->save($filename) ;

                        } else {
                            // otherwise - just save
                            $log .= ( "just save\n");
                            $image->save($filename, ['quality' => 80]); 
                        }
                }

                if ($format == 'p')
                {
                    $log .= ( "Portrait\n");

                    if ($size->getWidth() > $width)
                    {
                        $log .= ( "width({$size->getWidth()}) is greater than $width so resize\n");

                        // down-scale width to fit (we know that height is going to be too big)
                        $reduceRatio = $size->getWidth() / $width;
                        $tempHeight = $size->getHeight() / $reduceRatio;
                        $image->resize(new Box($width, $tempHeight));
                        // refresh size as we just changed it
                        $size = $image->getSize(); 
                    }

                    // if what we're left with is still to tall
                    if ($size->getHeight() > $height)
                    {
                        $log .= ( "height({$size->getHeight()}) is greater than $height so resize\n");

                        // then we can crop in
                        $offsetY = ($size->getHeight() - $height) / 2;
                        $image->crop(new Point(0, $offsetY), new Box($size->getWidth(), $height));

                    } 
                    $log .= ( "save\n");

                    $image->save($filename,['quality'=>80]);

                }


                /**
                 * If the image is smaller than it should be (thumbnail-ing really doesn't work properly!!!)
                 * then rebuild the thumbnail as square, then crop it
                 */
                if ($thumbnail && ($size->getWidth() < $width || $size->getHeight() < $height))
                {
                    $log .= (  "Thumbnailling\n".'\n' );
                    $image = $imagine->open($imagePath);
                    $image->resize(new Box($width, $width))
                            ->crop(new Point(0, 0), new Box($width, $height))
                            ->save($filename, ['quality' => 80]);
                }

                //Yii::info($imagePath,"IMAGECROP.imagePath");
                //var_dump($options);


            } 

            return self::thumbnailImg($filename, $width, $height, self::THUMBNAIL_OUTBOUND, array_merge($options, ['data-log' => ((YII_DEBUG) ? $log : '')]));

        }
        catch (Imagine\Exception\Exception $e)
        {
            $log .= ('ImageCrop unknown error: '.$e.'  '.$imagePath.'\n');
            return Html::img(self::DEFAULT_IMAGE, array_merge($options, ['data-log' => (YII_DEBUG) ? $log : '']));
        }
    }
    
    /**
     * Creates and caches the image thumbnail and returns ImageInterface.
     *
     * @param string $filename the image file path or path alias
     * @param integer $width the width in pixels to create the thumbnail
     * @param integer $height the height in pixels to create the thumbnail
     * @param string $mode self::THUMBNAIL_INSET, the original image
     * is scaled down so it is fully contained within the thumbnail dimensions.
     * The specified $width and $height (supplied via $size) will be considered
     * maximum limits. Unless the given dimensions are equal to the original image’s
     * aspect ratio, one dimension in the resulting thumbnail will be smaller than
     * the given limit. If self::THUMBNAIL_OUTBOUND mode is chosen, then
     * the thumbnail is scaled so that its smallest side equals the length of the
     * corresponding side in the original image. Any excess outside of the scaled
     * thumbnail’s area will be cropped, and the returned thumbnail will have
     * the exact $width and $height specified
     * @return \Imagine\Image\ImageInterface
     */
    public static function thumbnail($filename, $width, $height, $mode = self::THUMBNAIL_OUTBOUND)
    {
        return Image::getImagine()->open(self::thumbnailFile($filename, $width, $height, $mode));
    }

    /**
     * Creates and caches the image thumbnail and returns <img> tag.
     *
     * @param string $filename
     * @param integer $width
     * @param integer $height
     * @param string $mode
     * @param array $options options similarly with \yii\helpers\Html::img()
     * @return string
     */
    public static function thumbnailImg($filename, $width, $height, $mode = self::THUMBNAIL_OUTBOUND, $options = [])
    {
        $filename = FileHelper::normalizePath(Yii::getAlias($filename));
        try {
            $thumbnailFileUrl = self::thumbnailFileUrl($filename, $width, $height, $mode);
        } catch (FileNotFoundException $e) {
            $thumbnailUrl = self::$defaultImage;
            $options = array_merge($options, ['data-log2' => "::ThumbnailImg replaced default image({$filename})"]);
            return Html::img($thumbnailFileUrl,$options);
        } catch (\Exception $e) {
            Yii::warning("{$e->getCode()}\n{$e->getMessage()}\n{$e->getFile()}\nfilename: {$filename}");
            return 'Error ' . $e->getCode();
        }

        return Html::img(
            $thumbnailFileUrl,
            $options
        );
    }
    
    /**
     * 
     * The opposite to thumbnailFileUrl - workout path from url
     * 
     * @param type $filename
     * @param type $width
     * @param type $height
     * @param type $mode
     */
    public static function thumbnailPath($filename)
    {
        $parts = explode("/", $filename);
        array_shift($parts);
        array_shift($parts);
        array_shift($parts);
        
        $path = "/" . implode("/", $parts);
        
        return $path;
    }
    /**
     * Creates and caches the image thumbnail and returns URL from thumbnail file.
     *
     * @param string $filename
     * @param integer $width
     * @param integer $height
     * @param string $mode
     * @return string
     */
    public static function thumbnailFileUrl($filename, $width, $height, $mode = self::THUMBNAIL_OUTBOUND)
    {
        $filename = FileHelper::normalizePath(Yii::getAlias($filename));
        $cacheUrl = self::getThumbnailDir($filename, $width, $height);
        
        // CB added - if the filename contains the cache path, then the name has already been generated
        // even if the thumbfile doesn't exist ~ we may wish to generate the thumb later
        if (strpos($filename,$cacheUrl) === false)
            $thumbnailFilePath = self::thumbnailFile($filename, $width, $height, $mode);
        else
            $thumbnailFilePath = $filename;

        // preg_match('#[^\\' . DIRECTORY_SEPARATOR . ']+$#', $thumbnailFilePath, $matches);
        // $fileName = $matches[0];
        
        $file = basename($filename);

        return Yii::getAlias(self::CACHE_URL) . '/' . self::getThumbnailDir($filename, $width, $height) . '/' . $file;
    }
    /**
     * Creates and caches the image thumbnail and returns full path from thumbnail file.
     *
     * @param string $filename
     * @param integer $width
     * @param integer $height
     * @param string $mode
     * @return string
     * @throws FileNotFoundException
     */
    public static function thumbnailFile($filename, $width, $height, $mode = self::THUMBNAIL_OUTBOUND)
    {
        Yii::info($filename,'ImageCrop: File not found');
            
        $filename = FileHelper::normalizePath(Yii::getAlias($filename));
        if (!is_file($filename)) {
            Yii::info($filename,'ImageCrop: File not found');
            return self::DEFAULT_IMAGE;
        }
        
        $thumbnailFile = self::getCacheFile($filename, $width, $height, $mode);

        $box = new Box($width, $height);
        $image = Image::getImagine()->open($filename);
        $image = $image->thumbnail($box, $mode);

        $image->save($thumbnailFile);
        return $thumbnailFile;
    }

    public static function getCacheFile($filename, $width, $height, $mode)
    {
        $cachePath = Yii::getAlias(self::CACHE_ALIAS);

        $thumbnailDir = self::getThumbnailDir($filename, $width,$height);
        //$thumbnailFileExt = strrchr($filename, '.');
        $thumbnailFileName = basename($filename); //md5($filename . $width . $height . $mode . filemtime($filename));
        $thumbnailFilePath = $cachePath . DIRECTORY_SEPARATOR . $thumbnailDir;
        $thumbnailFile = DIRECTORY_SEPARATOR . $thumbnailFilePath . DIRECTORY_SEPARATOR . $thumbnailFileName;

        
        if (file_exists($thumbnailFile)) {
            if (self::$cacheExpire !== 0 && (time() - filemtime($thumbnailFile)) > self::$cacheExpire) {
                unlink($thumbnailFile);
            } else {
                return $thumbnailFile;
            }
        }
        if (!is_dir($thumbnailFilePath)) {
            mkdir($thumbnailFilePath, 0755, true);
        }
        
        return $thumbnailFile;
    }
    
    public static function getThumbnailDir($filename, $width, $height)
    {
        $file = basename($filename);
        return '.cache/t'.$width.'x'.$height . DIRECTORY_SEPARATOR . strtolower(substr($file, 0, 2));
    }
    /**
     * Clear cache directory.
     *
     * @return bool
     */
    public static function clearCache()
    {
        $cacheDir = Yii::getAlias('@webroot/' . self::$cacheAlias);
        self::removeDir($cacheDir);
        return @mkdir($cacheDir, 0755, true);
    }

    protected static function removeDir($path)
    {
        if (is_file($path)) {
            @unlink($path);
        } else {
            array_map('self::removeDir', glob($path . DIRECTORY_SEPARATOR . '*'));
            @rmdir($path);
        }
    }
}
