<?php
    # This plugin generates Tiled Pyramidal TIFF files when uploading a new image

    # Convert image to PTIF after the upload is successful
    function HookIiif_ptifAllUploadfilesuccess($resourceId)
    {
        global $iiif_ptif_commands;

        # Get the path to the original image. We need to select the extension from the database for this
        $extension = sql_value("SELECT file_extension value FROM resource WHERE ref = '" . escape_check($resourceId) . "'", 'tif');
        $sourcePath = get_resource_path($resourceId, true, '', true, $extension);
        $destPath = getPtifFilePath($resourceId);

        $catchallCommand = null;
        $processed = false;

        # Loop through the list of available conversion commands
        foreach($iiif_ptif_commands as $command) {
            # Find the catchall command
            if(in_array('*', $command['extensions'])) {
                $catchallCommand = $command;
            }
            # Find the appropriate command based on the extension
            else if(in_array($extension, $command['extensions'])) {
                $processed = true;
                executeConversion($command, $sourcePath, $destPath);
            }
        }

        # If no appropriate command was found based on the extension, use the catchall command
        if(!$processed) {
            executeConversion($catchallCommand, $sourcePath, $destPath);
        }
    }

    # Return the path where to store PTIF files in when uploading a new image
    function getPtifFilePath($ref, $forcedFolder = NULL)
    {
        global $storagedir, $iiif_ptif_filestore, $iiif_ptif_public_folder, $iiif_ptif_private_folder;

        $dir = $storagedir . $iiif_ptif_filestore;

        if($forcedFolder != NULL) {
            $dir .= $forcedFolder;
        } else if(isPublicImage($ref)) {
            $dir .= $iiif_ptif_public_folder;
        } else {
            $dir .= $iiif_ptif_private_folder;
        }

        # Create the directory to store the PTIF image if it does not yet exist
        if(!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir . $ref . '.tif';
    }

    # Determine if this image should be made publicly available or not
    function isPublicImage($ref)
    {
        global $iiif_ptif_public_key, $iiif_ptif_public_value, $iiif_ptif_public_folder;

        $public = false;
        if($iiif_ptif_public_key != NULL && $iiif_ptif_public_folder != null) {
            $data = get_resource_field_data($ref);

            foreach($data as $field) {
                if ($field['name'] == $iiif_ptif_public_key) {
                    $expl = explode(',', $field['value']);
                    foreach ($expl as $val) {
                        if ($val == $iiif_ptif_public_value) {
                            $public = true;
                            break;
                        }
                    }
                }
            }
        }
        return $public;
    }

    # Execute the actual image conversion
    function executeConversion($command, $sourcePath, $destPath)
    {
        $destPath = escapeshellarg($destPath);

        # Append prefix to the output filename if needed
        if(in_array('prefix', $command)) {
            $destPath = $command['prefix'] . $destPath;
        }

        # Append postfix to the output filename if needed
        if(in_array('postfix', $command)) {
            $destPath = $destPath . $command['postfix'];
        }

        $sourcePath = escapeshellarg($sourcePath);

        # Append the arguments to the command
        if(in_array('arguments', $command)) {
            $sourcePath = $command['arguments'] . ' ' . $sourcePath;
        }

        $cmd = $command['command'] . ' ' . $sourcePath . ' ' . $destPath;

        $output = run_command($cmd);
    }

    # Delete any generated PTIF files associated with this resource when the resource is being deleted
    # Requires $resource_deletion_state to be set to NULL due to a bug in ResourceSpace where nothing is actually deleted otherwise
    # This bug resides in include/resource_functions.php:2015.
    function HookIiif_ptifAllBeforedeleteresourcefromdb($ref)
    {
        $path = getPtifFilePath($ref);
        if(file_exists($path)) {
            unlink($path);
        }
    }

    # Renders clickable URL's to IIIF viewers above the preview image when opening a resource
    # Configure the $iiif_ptif_viewers field in config.php to generate appropriate URL's
    function HookIiif_ptifAllRenderbeforeresourceview($resource)
    {
        global $iiif_ptif_viewers;

        if(isset($iiif_ptif_viewers)) {
            foreach($iiif_ptif_viewers as $key => $viewer) {
                $url = str_replace('{ref}', $resource['ref'] . '.tif', $viewer);
                $file_headers = @get_headers($url);
                if(!$file_headers || $file_headers[0] == 'HTTP/1.1 404 Not Found') {
                    echo '<p>There is currently no working link to ' . $key . ' yet.</p>';
                }
                else {
                    echo '<p><a href=' . $url . '>View in' . $key . '</a></p>';
                }
            }
        }
    }

    # In case the public use field has updated, move the PTIF to the correct subdirectory
    # WARNING: does NOT trigger when a field is edited through the ResourceSpace API!!
    function HookIiif_ptifAllAftersaveresourcedata($ref, $nodes_to_add, $nodes_to_remove, $autosave_field)
    {
        global $iiif_ptif_public_folder, $iiif_ptif_private_folder;

        if(isPublicImage($ref)) {
            $oldFile = getPtifFilePath($ref, $iiif_ptif_private_folder);
            if(file_exists($oldFile)) {
                rename($oldFile, getPtifFilePath($ref));
            }
        } else {
            $oldFile = getPtifFilePath($ref, $iiif_ptif_public_folder);
            if(file_exists($oldFile)) {
                rename($oldFile, getPtifFilePath($ref));
            }
        }
    }
?>
