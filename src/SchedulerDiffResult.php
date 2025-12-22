<?php

final class GcsSchedulerDiffResult
{
    /** @var array<int,array<string,mixed>> */
    private array $toCreate = [];
    /** @var array<int,array<string,mixed>> */
    private array $toUpdate = [];
    /** @var array<int,array<string,mixed>> */
    private array $toDelete = [];

    /**
     * @param array<int,array<string,mixed>> $create
     * @param array<int,array<string,mixed>> $update
     * @param array<int,array<string,mixed>> $delete
     */
    public function __construct(array $create, array $update, array $delete)
    {
        $this->toCreate = $create;
        $this->toUpdate = $update;
        $this->toDelete = $delete;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getToCreate(): array
    {
        return $this->toCreate;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getToUpdate(): array
    {
        return $this->toUpdate;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getToDelete(): array
    {
        return $this->toDelete;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'create' => $this->toCreate,
            'update' => $this->toUpdate,
            'delete' => $this->toDelete,
        ];
    }
}
