<?php

use App\Domains\Assets\Models\Asset;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

/**
 * File-system module configuration.
 *
 * @return array{
 *     gotenberg_url: string,
 *     preview_mime_types: array<int, string>,
 *     trash_retention_days: int,
 *     max_upload_kilobytes: int,
 *     allowed_owner_types: array<int, class-string>,
 * }
 */
return [
    'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),

    // Path to the exiftool binary used for metadata extraction and RAW preview
    // extraction. Override in env if it isn't on PATH.
    'exiftool_binary' => env('EXIFTOOL_BINARY', 'exiftool'),

    // Polymorphic owner types this app accepts for FileItem ownership.
    // Add new types (Building, Customer, Property…) here as the domain grows
    // — the controller refuses to morph to anything not on this list to
    // prevent crafted owner_type payloads from probing arbitrary classes.
    'allowed_owner_types' => [
        User::class,
        Workspace::class,
        Asset::class,
    ],

    // Per-file upload size limit applied to files.* validation. Expressed
    // in kilobytes to match Laravel's `max:` validation units.
    'max_upload_kilobytes' => max(1, (int) env('FILES_MAX_UPLOAD_KB', 51200)),

    // Mime types for which we attempt an office→PDF→image preview. Images
    // skip this pipeline — they already have their own medialibrary conversions.
    'preview_mime_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
        'text/rtf',
        'application/rtf',
    ],

    // Camera RAW extensions. Browsers can't render these and the uploaded
    // mime is usually application/octet-stream, so we detect by extension and
    // generate a normalized JPEG preview (embedded JPEG via exiftool, else
    // ImageMagick). Add formats as needed.
    'raw_extensions' => ['arw', 'cr2', 'cr3', 'nef', 'dng', 'raf', 'orf', 'rw2', 'srw', 'pef'],

    // Non-RAW image formats browsers also can't (reliably) display inline; we
    // rasterize them to JPEG via ImageMagick/Imagick.
    'rasterize_extensions' => ['tif', 'tiff', 'heic', 'heif'],

    // Plain-text / source-code / data files we preview inline instead of
    // forcing a download. Markdown extensions additionally render formatted.
    'text_extensions' => [
        'txt', 'md', 'markdown', 'json', 'xml', 'csv', 'tsv', 'log', 'yml', 'yaml',
        'ini', 'conf', 'env', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'html',
        'php', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'sh', 'bash', 'sql',
    ],
    'markdown_extensions' => ['md', 'markdown'],

    // Cap how much of a text file we stream into the inline preview (bytes).
    'text_preview_max_bytes' => 256 * 1024,

    // Clamp to at least 1 — downstream commands subtract this from "now" to
    // compute the cutoff; 0 or negative would hard-delete everything on the
    // next scheduled run.
    'trash_retention_days' => max(1, (int) env('FILES_TRASH_DAYS', 30)),
];
