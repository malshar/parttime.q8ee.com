<?php

namespace App\Services\Sections;

final class ImportPlan
{
    /** @var string[] */
    public array $insert = [];

    /** @var string[] */
    public array $update = [];

    /** @var string[] */
    public array $unchanged = [];

    /** @var string[] */
    public array $delete = [];

    /** @var string[] */
    public array $flag = [];

    /** @return array{insert:int, update:int, unchanged:int, delete:int, flag:int} */
    public function counts(): array
    {
        return ['insert' => count($this->insert), 'update' => count($this->update), 'unchanged' => count($this->unchanged), 'delete' => count($this->delete), 'flag' => count($this->flag)];
    }

    public function auditSuffix(): string
    {
        $c = $this->counts();

        return "+{$c['insert']}/~{$c['update']}/={$c['unchanged']}/-{$c['delete']}/!{$c['flag']}";
    }
}
