<?php

namespace App\Jobs;

use App\FileImport;
use App\Repositories\UserRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Scaffold\Services\SyncUserService;

class UserCreate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var FileImport $fileImport */
    private $fileImport;

    /** @var array $userAttributes */
    private $userAttributes;

    /** @var array $courseAttributes */
    private $courseAttributes;

    public function __construct(FileImport $fileImport, array $userAttributes, array $courseAttributes)
    {
        $this->fileImport = $fileImport;
        $this->userAttributes = $userAttributes;
        $this->courseAttributes = $courseAttributes;
    }

    public function handle(SyncUserService $syncUserService, UserRepository $userRepository)
    {
        logger()->info('creating user: ', $this->userAttributes);

        /** @var \App\User $user */
        $user = $userRepository->create(
            $this->fileImport,
            $this->userAttributes,
            $this->courseAttributes
        );

        if ($user) {
            $syncUserService->create($user);
        }
    }

    public function failed()
    {
        app()->instance(Client::class, null);
    }
}
