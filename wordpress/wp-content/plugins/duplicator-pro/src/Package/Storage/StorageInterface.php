<?php

/**
 * @package Duplicator
 * @copyright (c) 2022, Snap Creek LLC
 */

namespace Duplicator\Package\Storage;

use Duplicator\Utils\IncrementalStatusMessage;

interface StorageInterface
{
    /**
     * @return bool Method for validating the cases that could cause failures for the file storage
     */
    public function isValid();

    /**
     * @return mixed valid storage id
     */
    public function getStorageId();

    /**
     * @return mixed valid storage directory path
     */
    public function getStoragePath();

    /**
     * @return string generated test file name
     */
    public function getTestFileName();

    /**
     * @return bool gets the status for the API response
     */
    public function getStatus();

    /**
     * @return string gets the overall message
     */
    public function getMessage();

    /**
     * @param string $message overall message that should be set
     * @return void
     */
    public function setMessage($message);

    /**
     * @param IncrementalStatusMessage $statusMessage adds an incremental message
     * @return void
     */
    public function addStatusMessage($statusMessage);

    /**
     * @return IncrementalStatusMessage gets all incremental messages
     */
    public function getStatusMessages();

    /**
     * @return string[] gets the construction filtered data
     */
    public function getInputData();

    /**
     * @return string gets the full file path for the test file
     */
    public function getFullTestFilePath();

    /**
     * @return string[] gets the response of the current local storage state for the API response
     */
    public function getResponseForAPI();
}
