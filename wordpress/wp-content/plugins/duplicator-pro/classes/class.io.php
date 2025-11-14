<?php

/**
 * @copyright 2016 Snap Creek LLC
 * Class for all IO operations
 */

defined("ABSPATH") or die("");


class DUP_PRO_IO
{
    /**
     * Safely copies a file to a directory
     *
     * @param string $source_file       The full filepath to the file to copy
     * @param string $dest_dir          The full path to the destination directory were the file will be copied
     * @param string $delete_first      Delete file before copying the new one
     * @param string $dest_filename     Destination filename
     *
     * @return TRUE on success or if file does not exist. FALSE on failure
     * 
     * @todo remove this
     */
    public static function copyFile($source_file, $dest_dir, $delete_first = false, $dest_filename = '')
    {
        //Create directory
        if (file_exists($dest_dir) == false) {
            if (wp_mkdir_p($dest_dir) === false) {
                DUP_PRO_Log::traceError("Error creating $dest_dir.");
                return false;
            }
        }

        //Remove file with same name before copy
        $filename = !empty($dest_filename) ? $dest_filename : basename($source_file);
        $dest_filepath = $dest_dir . "/$filename";
        if ($delete_first) {
            if (file_exists($dest_filepath)) {
                if (@unlink($dest_filepath) === false) {
                    DUP_PRO_Log::traceError("Could not delete file: {$dest_filepath}");
                    return false;
                }
            }
        }

        return copy($source_file, $dest_filepath);
    }
}
