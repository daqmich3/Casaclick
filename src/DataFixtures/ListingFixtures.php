<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/** Sample approved listings — same data mobile + website marketplace use. */
class ListingFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var UserRepository $users */
        $users = $manager->getRepository(User::class);
        $landlord = $users->findOneBy(['email' => 'landlord@example.com']);
        if (!$landlord) {
            return;
        }

        $studio = new Category();
        $studio->setName('Studio');
        $manager->persist($studio);

        $oneBr = new Category();
        $oneBr->setName('1 Bedroom');
        $manager->persist($oneBr);

        $listings = [
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

        foreach ($listings as $row) {
            $product = new Product();
            $product->setName($row['name']);
            $product->setPrice($row['price']);
            $product->setDescription($row['description']);
            $product->setImage($row['image']);
            $product->setCategory($row['category']);
            $product->setCreatedBy($landlord);
            $product->setUpdatedBy($landlord);
            $product->setStatus('approved');
            $manager->persist($product);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
