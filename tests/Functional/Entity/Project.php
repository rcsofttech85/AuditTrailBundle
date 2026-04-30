<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;

#[ORM\Entity]
#[ORM\Table(name: 'project')]
#[Auditable]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * @var Collection<int, ProjectTask>
     */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: ProjectTask::class, cascade: ['persist'])]
    public private(set) Collection $tasks;

    public function __construct(
        #[ORM\Column]
        public private(set) string $name,
    ) {
        $this->tasks = new ArrayCollection();
    }

    public function addProjectTask(ProjectTask $task): void
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setProject($this);
        }
    }

    public function removeProjectTask(ProjectTask $task): void
    {
        if ($this->tasks->removeElement($task) && $task->project === $this) {
            $task->setProject(null);
        }
    }
}
