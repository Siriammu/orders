<?php

declare(strict_types=1);

namespace OrderInsights\Controller;

use OrderInsights\Http\HttpException;
use OrderInsights\Import\CsvParser;
use OrderInsights\Import\ImportException;
use OrderInsights\Repository\OrderWriter;
use OrderInsights\Support\Config;
use PDO;

/** POST /api/uploads — multipart CSV in, import report out. */
final class UploadController
{
    private const ALLOWED_MIME = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{0:int, 1:\JsonSerializable} */
    public function __invoke(): array
    {
        $file = $this->uploadedFile();

        $tmp = $file['tmp_name'];
        $name = basename((string) $file['name']);   // client value: never used as a path

        try {
            $result = (new CsvParser())->parse($tmp);
        } catch (ImportException $e) {
            throw new HttpException($e->httpStatus, $e->getMessage(), $e->details);
        }

        (new OrderWriter($this->pdo))->save($result, $name, hash_file('sha256', $tmp));

        return [200, $result];
    }

    /** @return array{name:string, tmp_name:string, size:int, error:int} */
    private function uploadedFile(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            throw new HttpException(415, 'Send the CSV as multipart/form-data in a field named "file".');
        }

        // Body larger than post_max_size: PHP silently drops $_FILES.
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($_FILES === [] && $length > 0) {
            throw new HttpException(413, 'The upload is too large. The limit is ' . $this->limitLabel() . '.');
        }

        // Accept the conventional "file" field; fall back to the single file sent.
        $file = $_FILES['file'] ?? (count($_FILES) === 1 ? reset($_FILES) : null);
        if (!is_array($file) || is_array($file['error'] ?? null)) {
            throw new HttpException(400, 'Attach exactly one CSV file in a field named "file".');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new HttpException(413, 'The upload is too large. The limit is ' . $this->limitLabel() . '.');
            case UPLOAD_ERR_NO_FILE:
                throw new HttpException(400, 'No file was attached. Choose a CSV file and try again.');
            default:
                throw new HttpException(500, 'The server could not receive the file. Try again.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new HttpException(400, 'Invalid upload.');
        }
        if ($file['size'] > Config::MAX_UPLOAD_BYTES) {
            throw new HttpException(413, 'The upload is too large. The limit is ' . $this->limitLabel() . '.');
        }
        if ($file['size'] === 0) {
            throw new HttpException(400, 'The file is empty. Upload a CSV with a header row.');
        }

        // Trust the bytes, not the client's Content-Type or file name.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new HttpException(415, "That file is not a CSV (detected {$mime}). Export the orders as CSV and upload again.");
        }

        return $file;
    }

    private function limitLabel(): string
    {
        return (int) (Config::MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB';
    }
}
