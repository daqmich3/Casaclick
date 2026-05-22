<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotent demo data for Railway / empty databases (users + approved listings).
 */
class BootstrapDemoDataService
{
    public function __construct(
        private readonly BootstrapUsersService $bootstrapUsers,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * @return array{users: list<string>, listings: int, categories: int}
     */
    public function ensureDemoEnvironment(): array
    {
        $createdUsers = $this->bootstrapUsers->ensureDemoUsers();
        $listingResult = $this->ensureDemoListings();

        return [
            'users' => $createdUsers,
            'listings' => $listingResult['listings'],
            'categories' => $listingResult['categories'],
        ];
    }

    /**
     * @return array{listings: int, categories: int}
     */
    private function ensureDemoListings(): array
    {
        if ($this->productRepository->count(['status' => 'approved']) > 0) {
            return ['listings' => 0, 'categories' => 0];
        }

        $landlord = $this->userRepository->findOneBy(['email' => 'landlord@example.com']);
        if (!$landlord) {
            return ['listings' => 0, 'categories' => 0];
        }

        $studio = $this->findOrCreateCategory('Studio');
        $oneBr = $this->findOrCreateCategory('1 Bedroom');

        $rows = [
            [
                'name' => 'Sunrise Studio — Makati',
                'price' => 12500.0,
                'description' => 'Bright studio near Ayala. WiFi included. Ideal for students and young professionals.',
                'category' => $studio,
                'image' => '693b279e9ee3f.jpg',
            ],
            [
                'name' => 'Greenview 1BR — Quezon City',
                'price' => 18000.0,
                'description' => 'Spacious one-bedroom with balcony. Pet-friendly building, 24/7 security.',
                'category' => $oneBr,
                'image' => '693ba9889d885.jpg',
            ],
            [
                'name' => 'Harbor Loft — Pasig',
                'price' => 22000.0,
                'description' => 'Modern loft near Ortigas. Gym and pool access. Move-in ready.',
                'category' => $oneBr,
                'image' => '693bc8cfaba83.jpg',
            ],
        ];

        $count = 0;
        foreach ($rows as $row) {
            $product = new Product();
            $product->setName($row['name']);
            $product->setPrice($row['price']);
            $product->setDescription($row['description']);
            $product->setImage($row['image']);
            $product->setCategory($row['category']);
            $product->setCreatedBy($landlord);
            $product->setUpdatedBy($landlord);
            $product->setStatus('approved');
            $this->em->persist($product);
            ++$count;
        }

        $this->em->flush();

        return ['listings' => $count, 'categories' => 2];
    }

    private function findOrCreateCategory(string $name): Category
    {
        $existing = $this->em->getRepository(Category::class)->findOneBy(['name' => $name]);
        if ($existing) {
            return $existing;
        }

        $category = new Category();
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }
}
