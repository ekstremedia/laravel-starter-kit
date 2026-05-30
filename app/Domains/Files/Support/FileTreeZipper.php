<?php

declare(strict_types=1);

namespace App\Domains\Files\Support;

use App\Domains\Files\Models\FileItem;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Builds a ZIP of FileItem trees on disk and returns the temp path. Folders
 * recurse into subdirectories; collisions at a level are de-duplicated with a
 * numeric suffix. The reusable counterpart to FileItemController's own private
 * zip helpers — new file-owning modules (Equipment today) share this instead of
 * re-implementing the recursion. Caller is responsible for streaming the file
 * and deleting it afterwards (response()->download(...)->deleteFileAfterSend()).
 */
class FileTreeZipper
{
    /**
     * Zip one or more named groups of root items. Each group becomes a top-level
     * folder (when its label is non-empty), so several owners can be bundled
     * without their files colliding.
     *
     * @param  array<int, array{label: string, items: Collection<int, FileItem>}>  $groups
     * @return string absolute path to the created temp zip
     */
    public function zipGroups(array $groups, string $prefix = 'bundle'): string
    {
        $zipPath = sys_get_temp_dir().'/'.$prefix.'-'.uniqid('', true).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create archive.');
        }

        $topUsed = [];
        foreach ($groups as $group) {
            $base = '';
            if ($group['label'] !== '') {
                $base = $this->uniqueEntry($group['label'], $topUsed).'/';
                $zip->addEmptyDir(rtrim($base, '/'));
            }

            $used = [];
            foreach ($group['items'] as $item) {
                $this->addItem($zip, $item, $base, $used);
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Recursively add a FileItem to the open zip. Files contribute their
     * original media; folders create a directory and recurse their children.
     *
     * @param  array<string, bool>  $usedNames  guards against name collisions at a level
     */
    public function addItem(ZipArchive $zip, FileItem $item, string $prefix, array &$usedNames): void
    {
        $name = $this->uniqueEntry($prefix.$item->name, $usedNames);

        if ($item->isFolder()) {
            $zip->addEmptyDir($name);
            foreach ($item->children()->with('media')->get() as $child) {
                $childUsed = [];
                $this->addItem($zip, $child, $name.'/', $childUsed);
            }

            return;
        }

        $media = $item->getFirstMedia('file');
        if ($media && is_file($media->getPath())) {
            $zip->addFile($media->getPath(), $name);
        }
    }

    /**
     * @param  array<string, bool>  $usedNames
     */
    private function uniqueEntry(string $name, array &$usedNames): string
    {
        $candidate = $name;
        $i = 1;
        while (isset($usedNames[$candidate])) {
            $i++;
            $dot = mb_strrpos($name, '.');
            $candidate = $dot !== false && $dot > 0
                ? mb_substr($name, 0, $dot).' ('.$i.')'.mb_substr($name, $dot)
                : $name.' ('.$i.')';
        }
        $usedNames[$candidate] = true;

        return $candidate;
    }
}
