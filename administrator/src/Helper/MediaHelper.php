<?php

    /**
     * @version    CVS: 1.0.0
     * @package    Com_Test
     * @author     Agamemnon <info@easylogic.gr>
     * @copyright  2024 Agamemnon
     * @license    GNU General Public License version 2 or later; see LICENSE.txt
     */

    namespace Alfa\Component\Alfa\Administrator\Helper;

    // No direct access
    defined('_JEXEC') or die;

    use Exception;
    use Joomla\CMS\Component\ComponentHelper;
    use Joomla\CMS\Factory;
    use Joomla\CMS\Filter\OutputFilter;
    use Joomla\CMS\HTML\HTMLHelper;
    use Joomla\Filesystem\File;
    use Joomla\Filesystem\Folder;
    use Joomla\String\StringHelper;

    class MediaHelper
    {
        /**
         * Saves and processes media items (uploads or existing selections).
         *
         * Iterates through the provided media array, handles file moving/copying,
         * resizing, and database insertion/updates.
         *
         * @param array $mediaData The array of media items from the request.
         * @param array $droppedMedia The array for drag-and-dropped files.
         * @param int $itemId The ID of the parent item these media belong to.
         * @param string $mediaOrigin Identifier for the media origin based on model name (e.g., 'item', 'category').
         * @param string $customFileName Alias-based filename to use when media_name_from_alias is enabled.
         *
         * @throws Exception
         */
        public static function saveMedia(array $mediaData, array $droppedMedia, int $itemId, string $mediaOrigin, string $customFileName = ''): true
        {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // configuration
            $params = ComponentHelper::getParams('com_alfa');

            $saveFolder = ltrim($params->get('media_save_location', 'images/media-zone'), '/');
            $absolutePath = JPATH_ROOT . '/' . $saveFolder;

            if (!Folder::exists($absolutePath)) {
                Folder::create($absolutePath);
            }

            $urlDefaultThumbnail = trim($params->get('url_thumbnail', ''));
            $allowedMimes = $params->get('media_mime', []);
            $outputFormat = $params->get('media_file_format', 'jpg');
            $quality = $params->get('media_image_quality', 80);
            $maxWidth = $params->get('media_image_width', 1920);
            $maxHeight = $params->get('media_image_height', 1080);
            $nameFromAlias = $params->get('media_name_from_alias', false) && !empty($customFileName);

            $dropIndex = 0;
            $ordering = 0;

            foreach ($mediaData as $id => $media) {
                // Check if media is new
                $isNew = str_contains($id, 'new-');

                // Determine type of newly added media (drag & dropped OR selected by Joomla's media picker)
                $isDrop = str_starts_with($media['source'], 'blob:');
                $isPicker = str_starts_with($media['source'], 'picker:');
                $isUrl = $media['type'] == 'url';

                // Process thumbnail upfront for all non-blob sources.
                // Blob thumbnails are handled later, after the main image is saved to disk.
                if (!str_contains($media['thumbnail'], 'blob:')) {
                    $finalThumbnail = self::processThumbnail(
                        rawThumbnail:       $media['thumbnail'],
                        defaultThumbPath:   $urlDefaultThumbnail,
                        absolutePath:       $absolutePath,
                        saveFolder:         $saveFolder,
                        params:             $params,
                        customFileName:     $customFileName,
                    );
                }

                // -------------------------------------------------------
                // NEW MEDIA
                // -------------------------------------------------------
                if ($isNew) {
                    $src = '';
                    $nameForFile = '';

                    // FIND THE UNIQUE NAME FOR THE FILE
                    if ($isDrop) { // Dropped Media Handler
                        //dropped media and mediaData has the same ordering sequence
                        if (isset($droppedMedia[$dropIndex])) {
                            $file = $droppedMedia[$dropIndex];
                            $noError = $file['error'] === 0;

                            $dropIndex++; //keep track of the ordering sequence
                            if ($noError) {
                                $src = $file['tmp_name'];

                                // Check if file naming based on alias is enabled
                                if ($nameFromAlias) {
                                    $file['name'] = $customFileName . '.' . $outputFormat;
                                }

                                // Prevent errors and overwrites by setting a unique file name if it already exists in the directory
                                $nameForFile = self::getUniqueFilename(
                                    name:           $file['name'],
                                    basePath:       $absolutePath,
                                    outputFormat:   $outputFormat,
                                );
                            }
                        }
                    } elseif ($isPicker) { // Joomla Media Picker Handler
                        $cleanPath = HTMLHelper::cleanImageURL($media['source']);
                        $cleanPath = str_replace('picker://', '', $cleanPath->url);

                        $src = JPATH_ROOT . '/' . $cleanPath;

                        // Check if file naming based on alias is enabled
                        if ($nameFromAlias) {
                            $cleanPath = pathinfo($cleanPath);
                            $cleanPath = $cleanPath['dirname'] . '/' . $customFileName . '.' . $cleanPath['extension'];
                        }

                        $nameForFile = self::getUniqueFilename(
                            name:           $cleanPath,
                            basePath:       $absolutePath,
                            outputFormat:   $outputFormat,
                        );
                    }

                    // PREPARE OBJECT FOR INSERT ON MEDIA TABLE
                    $insertObject = null;
                    if ($isUrl) { // URL HANDLER
                        $insertObject = (object) [
                            'item_id' => $itemId,
                            'path' => $media['source'],
                            'thumbnail' => $finalThumbnail,
                            'alt' => $media['alt'],
                            'ordering' => $ordering,
                            'type' => $media['type'],
                            'origin' => $mediaOrigin,
                        ];
                    } else {
                        // Check media source existence
                        $srcExists = !empty($src) && file_exists($src);

                        // If source exists, process media for uploading to database
                        if ($srcExists) {
                            $dest = $absolutePath . '/' . $nameForFile;

                            // Process image - configure extension, resize, compress, calculate dominant color. (based on user provided settings)
                            $color = self::mediaImageGenerator(
                                source_image_path:  $src,
                                new_image_path:     $dest,
                                output_type:        $outputFormat,
                                max_width:          $maxWidth,
                                max_height:         $maxHeight,
                                resize_if_smaller:  true,
                                quality:            $quality,
                                allowedMimes:       $allowedMimes,
                                aspectratio:        0,
                            );

                            // Blob URLs are browser-only and unreachable server-side, so on first save
                            // we generate the thumbnail from the main image. On subsequent saves the
                            // thumbnail is already a real path, so the processThumbnail() call at the
                            // top of the loop handles it instead and this block is skipped.
                            if ((str_contains($media['thumbnail'], 'blob:')) && $isDrop) {
                                $finalThumbnail = self::processThumbnail(
                                    rawThumbnail:       $saveFolder . '/' . $nameForFile,
                                    defaultThumbPath:   $urlDefaultThumbnail,
                                    absolutePath:       $absolutePath,
                                    saveFolder:         $saveFolder,
                                    params:             $params,
                                    customFileName:     $customFileName,
                                );
                            }

                            $colorExists = $color !== false;

                            if ($colorExists) {
                                $insertObject = (object) [
                                    'item_id' => $itemId,
                                    'path' => ltrim($saveFolder . '/' . $nameForFile, '/'),
                                    'thumbnail' => $finalThumbnail,
                                    'color' => $color,
                                    'alt' => $media['alt'],
                                    'ordering' => $ordering,
                                    'type' => $media['type'],
                                    'origin' => $mediaOrigin,
                                ];
                            }
                        }
                    }

                    if (!empty($insertObject)) {
                        $db->insertObject('#__alfa_media', $insertObject);
                    }
                }

                // -------------------------------------------------------
                // EXISTING MEDIA
                // -------------------------------------------------------
                else {
                    $toDelete = isset($media['delete']) && $media['delete'] == 1;
                    $isFullDelete = $params->get('media_full_deletion', false);

                    if ($toDelete) {
                        // If full delete is enabled, remove files from the server first
                        if ($isFullDelete) {
                            // Fetch the main image path associated with this thumbnail before deleting
                            $query = $db->getQuery(true)
                                ->select('path, thumbnail')
                                ->from($db->quoteName('#__alfa_media'))
                                ->where($db->quoteName('id') . ' = ' . $db->quote($id));

                            $db->setQuery($query);
                            $result = $db->loadObject();

                            // Remove both thumbnail and main image from server
                            $resultArray = [$result->path, $result->thumbnail];

                            foreach ($resultArray as $path) {
                                if ($path && file_exists(JPATH_ROOT . '/' . $path)) {
                                    File::delete(JPATH_ROOT . '/' . $path);
                                }
                            }
                        }

                        // Remove media record from database
                        $query = $db->getQuery(true)
                            ->delete($db->quoteName('#__alfa_media'))
                            ->where($db->quoteName('id') . ' = ' . (int) $id);

                        try {
                            $db->setQuery($query)->execute();
                        } catch (Exception $e) {
                            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
                            return true;
                        }
                    } else {
                        // Update ordering, alt text, and thumbnail for existing records
                        $obj = (object) [
                            'id' => (int) $id,
                            'path' => ltrim($media['source'], '/'),
                            'alt' => $media['alt'],
                            'ordering' => $ordering,
                            'thumbnail' => $finalThumbnail,
                        ];

                        $db->updateObject('#__alfa_media', $obj, 'id');
                    }
                }
                $ordering++;
            }
            return true;
        }

        /**
         * Processes and saves a thumbnail image.
         *
         * Returns an empty string if the thumbnail is missing or matches the default placeholder,
         * returns the existing path if it's already within the save folder,
         * otherwise processes the source image and saves a new thumbnail.
         *
         * @param string $rawThumbnail Raw (relative) thumbnail path.
         * @param string $defaultThumbPath Path of the default placeholder thumbnail to compare against.
         * @param string $absolutePath Absolute filesystem path to the media save directory.
         * @param string $saveFolder Relative path of the media save folder (e.g., 'images/media-zone').
         * @param object $params Component parameters object.
         * @param string $customFileName Optional alias-based filename to use instead of the original.
         *
         * @return string Relative path of the saved thumbnail, or empty string if none.
         */
        private static function processThumbnail(string $rawThumbnail, string $defaultThumbPath, string $absolutePath, string $saveFolder, object $params, string $customFileName): string
        {
            $outputFormat = $params->get('media_file_format', 'jpg');
            $thumbnailWidth = $params->get('media_thumbnail_width', 200);
            $thumbnailHeight = $params->get('media_thumbnail_height', 200);
            $quality = $params->get('media_image_quality', 80);
            $allowedMimes = $params->get('media_mime', []);
            $nameFromAlias = $params->get('media_name_from_alias', false);

            // Strip URL fragments (e.g., cache busters) and normalize leading slashes
            $cleanThumb = ltrim(strtok($rawThumbnail, '#'), '/');
            $cleanDefault = ltrim($defaultThumbPath, '/');

            // Save empty string when no thumbnail is set or user selected the default placeholder
            if (empty($cleanThumb) || $cleanThumb === $cleanDefault) {
                return '';
            }

            // Already processed — return as-is to avoid re-generating on subsequent saves
            if (str_starts_with($cleanThumb, $saveFolder)) {
                if (file_exists(JPATH_ROOT . '/' . $cleanThumb)) {
                    return $cleanThumb;
                }
            }

            $sourcePath = JPATH_ROOT . '/' . $cleanThumb;
            $baseName = pathinfo($cleanThumb, PATHINFO_FILENAME); // Handle cases where there's no extension (e.g., "README")
            $name = $nameFromAlias && !empty($customFileName) ? $customFileName : $baseName;

            $uniqueName = self::getUniqueFilename(
                name:           $name,
                basePath:       $absolutePath,
                outputFormat:   $outputFormat,
                suffix:         '-thumb',
            );

            $destPath = $absolutePath . '/' . $uniqueName;

            self::mediaImageGenerator(
                source_image_path:  $sourcePath,
                new_image_path:     $destPath,
                output_type:        $outputFormat,
                max_width:          $thumbnailWidth,
                max_height:         $thumbnailHeight,
                resize_if_smaller:  true,
                quality:            $quality,
                allowedMimes:       $allowedMimes,
                aspectratio:        0,
            );

            return ltrim($saveFolder . '/' . $uniqueName, '/');
        }

        /**
         * Generates a unique filename to prevent overwriting existing files.
         *
         * @param string $name Original or alias based filename (e.g., 'photo.jpg')
         * @param string $basePath The directory path to check for conflicts.
         * @param string $outputFormat The target file extension (e.g., 'webp').
         * @param string $suffix Optional suffix appended to the name (e.g., 'thumb').
         *
         * @return string The unique filename with extension.
         */
        private static function getUniqueFilename(
            string $name,
            string $basePath,
            string $outputFormat,
            string $suffix = '',
        ): string {
            $name = OutputFilter::stringURLSafe(pathinfo($name, PATHINFO_FILENAME));

            // Append suffix if provided (e.g., 'thumb' → 'my-article-thumb')
            if (!empty($suffix)) {
                $name .= '-' . OutputFilter::stringURLSafe($suffix);
            }

            // Increment until unique
            $candidate = $name;
            while (file_exists($basePath . '/' . $candidate . '.' . $outputFormat)) {
                $candidate = StringHelper::increment($candidate, 'dash');
            }

            return $candidate . '.' . $outputFormat;
        }

        /**
         * Get media data for items, optionally with placeholder fallback
         *
         * @param string $origin Media origin identifier
         * @param int|array $itemIDs Single ID or array of IDs
         *
         * @return array|object[] Media objects (single) or grouped array (multiple)
         */
        public static function getMediaData(string $origin, int|array|null $itemIDs, $usePlaceHolder = false): array
        {
            // 1. Config
            $params = ComponentHelper::getParams('com_alfa');
            $placeholderPath = trim($params->get('media_placeholder', ''));
            $urlThumbnailPath = trim($params->get('media_url_thumbnail', ''));

            // 2. Normalize input
            $single = !is_array($itemIDs);
            $itemIDs = array_map('intval', (array) $itemIDs);

            if (empty($itemIDs)) {
                return [];
            }

            // 3. Query
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_media'))
                ->whereIn($db->quoteName('item_id'), $itemIDs)
                ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                ->order($db->quoteName('ordering'));
            $db->setQuery($query);
            $results = $db->loadObjectList();

            // 4. Process results (single loop: thumbnails + grouping)
            $grouped = [];
            foreach ($results as $result) {
                // Resolve empty thumbnail first (while data is raw)
                if (empty($result->thumbnail)) {
                    $result->thumbnail = ltrim(self::resolveThumbnail(
                        result: $result,
                        urlThumbnailPath: $urlThumbnailPath,
                    ), '/');
                }

                // THEN clean both paths for output
                $result->path = ltrim($result->path, '/');

                $grouped[$result->item_id][] = $result;
            }

            // 5. Placeholder (if enabled)
            $placeholder = !empty($placeholderPath) && $usePlaceHolder
                ? self::getPlaceholderMedia($placeholderPath)
                : null;

            // 6. Return
            if ($single) {
                $firstGroup = reset($grouped) ?: [];
                return !empty($firstGroup) ? $firstGroup : ($placeholder ? [$placeholder] : []);
            }

            if ($placeholder) {
                $grouped += array_fill_keys($itemIDs, [$placeholder]);
            }

            return $grouped;
        }

        /**
         * Resolves a display thumbnail for records with no stored thumbnail.
         * Images fall back to their own path; URL media uses the configured URL thumbnail.
         */
        private static function resolveThumbnail(object $result, string $urlThumbnailPath): string
        {
            return match ($result->type) {
                'image' => $result->path,
                'url' => $urlThumbnailPath,
                default => $result->path
            };
        }

        /**
         * Get placeholder media object from component params
         *
         * @return object|null Placeholder media object or null if not configured
         */
        private static function getPlaceholderMedia(string $placeholderPath): ?object
        {
            // Create a pseudo-media object matching the structure of real media
            return (object) [
                'id' => 0,
                'item_id' => 0,
                'path' => $placeholderPath,
                'thumbnail' => $placeholderPath,
                'alt' => 'Placeholder image',
                'ordering' => 0,
                'type' => 'placeholder',
                'color' => null,
                'origin' => 'placeholder',
            ];
        }

        /**
         * Processes an image (resizes, converts, saves) and calculates its dominant color.
         *
         * Detailed overview:
         *
         * The function validates the source image's format and aspect ratio,
         * then calculates its dominant RGB color.
         * It resizes the image if necessary while preserving transparency,
         * and saves the converted file to the destination path in the
         * target format before returning the color value.
         *
         * @param string $source_image_path Image origin path
         * @param string $new_image_path Image destination path
         * @param string $output_type Preferred new format (e.g jpg, png, webp, gif, etc...) for image
         * @param int $max_width Image target width
         * @param int $max_height Image target height
         * @param bool $resize_if_smaller Enable/disable image resizing when smaller than max-width/height
         * @param int $quality Image compression strength
         * @param array $allowedMimes Allowed image MIME types set by user
         * @param int $aspectratio Image target aspect ratio
         *
         * @return false|string Image dominant color in RGB value e.g rgb(192,221,165)
         * @throws Exception
         */
        private static function mediaImageGenerator(string $source_image_path, string $new_image_path, string $output_type, int $max_width, int $max_height, bool $resize_if_smaller, int $quality, array $allowedMimes, int $aspectratio = 0): false|string
        {
            // Define constants if they aren't already defined
            if (!defined('NEW_IMAGE_MAX_WIDTH')) {
                define('NEW_IMAGE_MAX_WIDTH', $max_width);
            }
            if (!defined('NEW_IMAGE_MAX_HEIGHT')) {
                define('NEW_IMAGE_MAX_HEIGHT', $max_height);
            }

            $app = Factory::getApplication();

            // Safety check for file existence
            if (!file_exists($source_image_path)) {
                $app->enqueueMessage('Error finding uploaded file', 'error');
                return false;
            }

            // Get actual image dimensions and type
            $imageInfo = getimagesize($source_image_path);
            if (!$imageInfo) {
                return false;
            }

            $source_image_width = $imageInfo[0];
            $source_image_height = $imageInfo[1];
            $mime = $imageInfo['mime'];

            // Check if image MIME is allowed
            if (!in_array($mime, $allowedMimes)) {
                $mime = explode('/', strtoupper($mime))[1];
                $app->enqueueMessage("Image format ($mime) is not supported.", 'error');
                return false;
            }

            // --- ASPECT RATIO CHECK ---
            $checkAspectRatio = false;
            $aspectratioExploded = explode(':', (string) $aspectratio);
            if (count($aspectratioExploded) > 1 && intval($aspectratioExploded[0]) > 0 && intval($aspectratioExploded[1]) > 0) {
                $checkAspectRatio = true;
            }

            if ($checkAspectRatio) {
                $srcRatio = floatval(sprintf('%0.2f', $source_image_width / $source_image_height));
                $targetRatio = floatval(sprintf('%0.2f', intval($aspectratioExploded[0]) / intval($aspectratioExploded[1])));

                if ($srcRatio != $targetRatio) {
                    $app->enqueueMessage('Image should have the exact aspect ratio ' . $aspectratio, 'warning');
                    return false;
                }
            }

            // --- RESIZE LOGIC ---
            $resize = 1;
            if (($max_width == 0 && $max_height == 0) || ($source_image_width == $max_width || $source_image_height == $max_height)) {
                $resize = 0;
            }

            // --- LOAD IMAGE ---
            $loaders = [
                'image/jpeg' => 'imagecreatefromjpeg',
                'image/png' => 'imagecreatefrompng',
                'image/gif' => 'imagecreatefromgif',
                'image/webp' => 'imagecreatefromwebp',
                'image/avif' => 'imagecreatefromavif',
            ];

            $loaderFunction = $loaders[$mime]; // image/webp

            if (!function_exists($loaderFunction)) {
                $formatName = strtoupper(str_replace('imagecreatefrom', '', $loaderFunction));
                $app->enqueueMessage("Error: Server missing $formatName support (GD)", 'error');
                return false;
            }

            $source_gd_image = @$loaderFunction($source_image_path);

            if (!$source_gd_image) {
                $app->enqueueMessage('Error: File is corrupt or unreadable', 'error');
                return false;
            }

            if ($mime === 'image/png') {
                imagepalettetotruecolor($source_gd_image);
                imagealphablending($source_gd_image, true);
                imagesavealpha($source_gd_image, true);
            }

            // --- DOMINANT COLOR ---
            $r_total = $g_total = $b_total = $total = 0;
            // Optimization: Sample every 10th pixel instead of every single one for speed
            $step = 10;
            for ($x = 0; $x < imagesx($source_gd_image); $x += $step) {
                for ($y = 0; $y < imagesy($source_gd_image); $y += $step) {
                    $rgb = imagecolorat($source_gd_image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $r_total += $r;
                    $g_total += $g;
                    $b_total += $b;
                    $total++;
                }
            }
            $r = $total ? round($r_total / $total) : 0;
            $g = $total ? round($g_total / $total) : 0;
            $b = $total ? round($b_total / $total) : 0;
            $rgbColor = sprintf('rgb(%d,%d,%d)', $r, $g, $b);

            $image_to_output = $source_gd_image;
            $resize_gd_image = null;

            // --- PROCESS RESIZE ---
            if ($resize) {
                $source_aspect_ratio = $source_image_width / $source_image_height;
                $resize_aspect_ratio = $max_width / $max_height;

                if ($source_image_width <= $max_width && $source_image_height <= $max_height) {
                    if ($resize_if_smaller) {
                        if (($max_width - $source_image_width) > ($max_height - $source_image_height)) {
                            $newW = (int) ($max_height * $source_aspect_ratio);
                            $newH = $max_height;
                        } else {
                            $newW = $max_width;
                            $newH = (int) ($max_width / $source_aspect_ratio);
                        }
                    } else {
                        $newW = $source_image_width;
                        $newH = $source_image_height;
                    }
                } elseif ($resize_aspect_ratio > $source_aspect_ratio) {
                    $newW = (int) ($max_height * $source_aspect_ratio);
                    $newH = $max_height;
                } else {
                    $newW = $max_width;
                    $newH = (int) ($max_width / $source_aspect_ratio);
                }

                $resize_gd_image = imagecreatetruecolor($newW, $newH);

                // Handle Transparency
                if ($output_type == 'jpg' || $output_type == 'jpeg') {
                    $white = imagecolorallocate($resize_gd_image, 255, 255, 255);
                    imagefill($resize_gd_image, 0, 0, $white);
                } else {
                    imagealphablending($resize_gd_image, false);
                    imagesavealpha($resize_gd_image, true);
                    $transparent = imagecolorallocatealpha($resize_gd_image, 0, 0, 0, 127);
                    imagefill($resize_gd_image, 0, 0, $transparent);
                }

                imagecopyresampled($resize_gd_image, $source_gd_image, 0, 0, 0, 0, $newW, $newH, $source_image_width, $source_image_height);
                $image_to_output = $resize_gd_image;
            }

            // --- SAVE IMAGE ---
            switch ($output_type) {
                case 'jpg':
                case 'jpeg': $result = imagejpeg($image_to_output, $new_image_path, $quality);
                    break;
                case 'png':
                    $pngQuality = (int) (9 - round(($quality / 100) * 9));
                    $result = imagepng($image_to_output, $new_image_path, $pngQuality);
                    break;
                case 'gif': $result = imagegif($image_to_output, $new_image_path);
                    break;
                case 'webp': $result = imagewebp($image_to_output, $new_image_path, $quality);
                    break;
                case 'avif': $result = imageavif($image_to_output, $new_image_path);
                    break;
                default: $result = imagejpeg($image_to_output, $new_image_path, $quality);
            }

            // Cleanup
            imagedestroy($source_gd_image);
            if ($resize_gd_image) {
                imagedestroy($resize_gd_image);
            }

            return $result ? $rgbColor : false;
        }
    }