<?php

namespace App\Jobs;

use App\FileImport;
use App\SIA\Mappers\UserFileMapper;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ParseImportedFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    public $tries = 20;

    /** @var FileImport $fileImport */
    private $fileImport;

    public function __construct(FileImport $fileImport)
    {
        $this->fileImport = $fileImport;
    }

    public function handle(UserFileMapper $userFileMapper)
    {
        ini_set('memory_limit', '1G');
        logger('Analysing and mapping file');

        /** @var Collection */
        $existentUsers = User::query()->withTrashed()->select(['id', 'platform_id', 'chave', 'expired_at'])->get()
            ->groupBy('chave')->map->first();

        /** @var UserFileMapper */
        $mapper = $userFileMapper->map($this->fileImport);

        $this->createUsersIfNeeded($mapper, $existentUsers);
        $this->updatingUsersIfNeeded($mapper, $existentUsers);
        $this->deleteUsersIfNeeded($mapper, $existentUsers);
    }

    public function createUsersIfNeeded(UserFileMapper $mapper, Collection $existentUsers): void
    {
        $lines = $mapper->filter(function ($line) use ($existentUsers) {
            $exists = $existentUsers->has($line['user']['chave']);

            logger('Looking for chave: ' . $line['user']['chave'] . ' - ' . ($exists ? 'found' : 'missing'));

            return ! $exists;
        });

        if ($lines->isNotEmpty()) {
            logger('Creating ' . $lines->count() . ' users');
        }

        $lines->each(function ($line) {
            logger('User ', $line['user']);
            logger('Course: ', $line['course']);
            dispatch(new UserCreate(
                $this->fileImport,
                $line['user'],
                $line['course']
            ))->onQueue('create');
        });
    }

    public function updatingUsersIfNeeded(UserFileMapper $mapper, Collection $existentUsers): void
    {
        $lines = $mapper->filter(function ($line) use ($existentUsers) {
            return $existentUsers->has($line['user']['chave']);
        });

        if ($lines->isNotEmpty()) {
            logger('Updating ' . $lines->count() . ' users');
        }

        $lines->each(function ($line) {
            logger('User ', $line['user']);
            logger('Course: ', $line['course']);
            dispatch(new UserUpdate(
                $this->fileImport,
                $line['user'],
                $line['course']
            ))->onQueue('update');
        });
    }

    public function deleteUsersIfNeeded(UserFileMapper $mapper, Collection $existentUsers): void
    {
        // we want who: IS on existentUsers BUT IS NOT on mappedUsers
        $leftOvers = clone $existentUsers;
        foreach ($mapper->mappedUsers->pluck('user.chave')->all() as $arhKey) {
            /** @phan-suppress-next-line PhanTypeArrayUnsetSuspicious */
            unset($leftOvers[$arhKey]);
        }

        $lines = $leftOvers;

        if ($lines->isNotEmpty()) {
            logger('Deleting ' . $lines->count() . ' users');
        }

        $lines->each(function (User $user) {
            logger('User: ', $user->toArray());
            dispatch(new UserDelete($user))->onQueue('delete');
        });
    }
}
