<?php

namespace Livewire;

use Illuminate\Http\UploadedFile;
use Facades\Livewire\GenerateSignedUploadUrl;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\S3DoesntSupportMultipleFileUploads;

trait WithFileUploads
{
    public function startUpload($name, $fileInfo, $isMultiple)
    {
        if (FileUploadConfiguration::isUsingS3()) {
            foreach($fileInfo as $index => $file) {
                $file = UploadedFile::fake()->create($file['name'], $file['size'] / 1024, $file['type']);

                $this->emit('upload:generatedSignedUrlForS3', $name, GenerateSignedUploadUrl::forS3($file), $index)->self();
            }

            return;
        }

        $this->emit('upload:generatedSignedUrl', $name, GenerateSignedUploadUrl::forLocal())->self();
    }

    public function finishUpload($name, $tmpPath, $isMultiple)
    {
        $this->cleanupOldUploads();

        if ($isMultiple) {
            $file = collect($tmpPath)->map(function ($i) {
                return TemporaryUploadedFile::createFromLivewire($i);
            })->toArray();
            $this->emit('upload:finished', $name, collect($file)->map->getFilename()->toArray())->self();
        } else {
            $file = TemporaryUploadedFile::createFromLivewire($tmpPath[0]);
            $this->emit('upload:finished', $name, [$file->getFilename()])->self();

            // If the property is an array, but the upload ISNT set to "multiple"
            // then APPEND the upload to the array, rather than replacing it.
            if (is_array($value = $this->getPropertyValue($name))) {
                $file = array_merge($value, [$file]);
            }
        }

        $this->syncInput($name, $file);
    }

    public function uploadErrored($name, $errorsInJson, $isMultiple) {
        $this->emit('upload:errored', $name)->self();

        if (is_null($errorsInJson)) {
            // Handle any translations/custom names
            $translator = app()->make('translator');

            $attribute = $translator->get("validation.attributes.{$name}");
            if ($attribute === "validation.attributes.{$name}") $attribute = $name;

            $message = trans('validation.uploaded', ['attribute' => $attribute]);
            if ($message === 'validation.uploaded') $message = "The {$name} failed to upload.";

            throw ValidationException::withMessages([$name => $message]);
        }

        $errorsInJson = $isMultiple
            ? str_ireplace('files', $name, $errorsInJson)
            : str_ireplace('files.0', $name, $errorsInJson);

        $errors = json_decode($errorsInJson, true)['errors'];

        throw (ValidationException::withMessages($errors));
    }

    public function removeUpload($name, $tmpFilename)
    {
        $uploads = $this->getPropertyValue($name);

        if (is_array($uploads) && isset($uploads[0]) && $uploads[0] instanceof TemporaryUploadedFile) {
            $this->emit('upload:removed', $name, $tmpFilename)->self();

            $this->syncInput($name, array_values(array_filter($uploads, function ($upload) use ($tmpFilename) {
                if ($upload->getFilename() === $tmpFilename) {
                    $upload->delete();
                    return false;
                }

                return true;
            })));
        } elseif ($uploads instanceof TemporaryUploadedFile && $uploads->getFilename() === $tmpFilename) {
            $uploads->delete();

            $this->emit('upload:removed', $name, $tmpFilename)->self();

            $this->syncInput($name, null);
        }
    }

    protected function cleanupOldUploads()
    {
        if (FileUploadConfiguration::isUsingS3()) return;

        $storage = FileUploadConfiguration::storage();

        foreach ($storage->allFiles(FileUploadConfiguration::path()) as $filePathname) {
            // On busy websites, this cleanup code can run in multiple threads causing part of the output
            // of allFiles() to have already been deleted by another thread.
            if (! $storage->exists($filePathname)) continue;

            $yesterdaysStamp = now()->subDay()->timestamp;
            if ($yesterdaysStamp > $storage->lastModified($filePathname)) {
                $storage->delete($filePathname);
            }
        }
    }
}
