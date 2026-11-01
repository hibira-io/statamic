<?php

namespace Hibira\Statamic\Tests;

use Illuminate\Support\Collection;
use Statamic\Auth\File\RoleRepository;
use Statamic\Contracts\Auth\RoleRepository as RepositoryContract;
use Statamic\Facades\Role;

/** Roles kept in memory (Statamic 5 has no FakesRoles helper). */
trait FakesRoles
{
    /** @param  array<string, list<string>>  $roles */
    protected function setTestRoles(array $roles): void
    {
        $roles = collect($roles)->map(fn ($permissions, $handle) => Role::make()->handle($handle)->addPermission($permissions));

        $fake = new class($roles) extends RoleRepository
        {
            public function __construct(protected Collection $fakeRoles) {}

            public function all(): Collection
            {
                return $this->fakeRoles;
            }

            public function find(string $id): ?\Statamic\Contracts\Auth\Role
            {
                return $this->fakeRoles->get($id);
            }
        };

        app()->instance(RepositoryContract::class, $fake);
        Role::swap($fake);
    }
}
