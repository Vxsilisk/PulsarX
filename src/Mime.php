<?php

/**
 * PulsarX — multipart/form-data builder (file uploads included).
 *
 * Produces the field array cURL serialises into a multipart body:
 *
 *   $mime = (new Mime)
 *       ->addPart('field', data: 'value')
 *       ->addPart('avatar', filename: 'a.png', contentType: 'image/png', localPath: '/tmp/a.png');
 *   $client->post($url, $mime);
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
class Mime
{
    /** @var array<int, array<string, ?string>> */
    private array $parts = [];

    public function addPart(
        string  $name,
        ?string $contentType = null,
        ?string $filename = null,
        ?string $data = null,
        ?string $localPath = null,
    ): static {
        if ($localPath !== null && $data !== null) {
            throw new PulsarException('Mime::addPart() accepts either data or localPath, not both.');
        }

        $this->parts[] = compact('name', 'contentType', 'filename', 'data', 'localPath');
        return $this;
    }

    /**
     * @param array<int, array<string, ?string>> $list each: ['name'=>, 'data'|'localPath'=>, 'filename'?=>, 'contentType'?=>]
     */
    public static function fromList(array $list): static
    {
        $mime = new static();
        foreach ($list as $p) {
            $mime->addPart(
                name: $p['name'],
                contentType: $p['contentType'] ?? $p['content_type'] ?? null,
                filename: $p['filename'] ?? null,
                data: $p['data'] ?? null,
                localPath: $p['localPath'] ?? $p['local_path'] ?? null,
            );
        }
        return $mime;
    }

    /** Build the array cURL serialises into a multipart/form-data body. */
    public function toPostFields(): array
    {
        $fields = [];

        foreach ($this->parts as $p) {
            if ($p['localPath'] !== null) {
                $fields[$p['name']] = new CURLFile(
                    $p['localPath'],
                    $p['contentType'] ?? '',
                    $p['filename'] ?? basename($p['localPath'])
                );
            } elseif ($p['filename'] !== null) {
                // in-memory file part (PHP 8.1+)
                $fields[$p['name']] = new CURLStringFile(
                    $p['data'] ?? '',
                    $p['filename'],
                    $p['contentType'] ?? 'application/octet-stream'
                );
            } else {
                $fields[$p['name']] = $p['data'] ?? '';
            }
        }

        return $fields;
    }
}
