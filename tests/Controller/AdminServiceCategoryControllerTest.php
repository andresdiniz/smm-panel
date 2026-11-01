<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Admin\AdminServiceCategoryController;
use App\Entity\ServiceCategory;
use App\Repository\ServiceCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminServiceCategoryControllerTest extends TestCase
{
    public function testGeneratedCategorySlugIsNormalized(): void
    {
        $controller = $this->createControllerWithRepository(
            $this->createStub(ServiceCategoryRepository::class),
        );

        self::assertSame('instagram-likes', $this->generateSlug($controller, 'Instagram Likes'));
    }

    public function testGeneratedCategorySlugUsesNumericSuffixWhenAlreadyTaken(): void
    {
        $repository = $this->createMock(ServiceCategoryRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls(new ServiceCategory(), null);
        $controller = $this->createControllerWithRepository($repository);

        self::assertSame('instagram-likes-2', $this->generateSlug($controller, 'Instagram Likes'));
    }

    public function testGeneratedCategorySlugHasFallbackForNamesWithoutAsciiCharacters(): void
    {
        $controller = $this->createControllerWithRepository(
            $this->createStub(ServiceCategoryRepository::class),
        );

        self::assertSame('categoria', $this->generateSlug($controller, '!!!'));
    }

    private function createControllerWithRepository(ServiceCategoryRepository $repository): AdminServiceCategoryController
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(ServiceCategory::class)
            ->willReturn($repository);

        return new AdminServiceCategoryController($entityManager);
    }

    private function generateSlug(AdminServiceCategoryController $controller, string $name): string
    {
        $method = new ReflectionMethod($controller, 'makeUniqueSlug');

        return $method->invoke($controller, $name);
    }
}
