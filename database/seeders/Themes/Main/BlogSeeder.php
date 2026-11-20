<?php

namespace Database\Seeders\Themes\Main;

use Botble\Blog\Database\Traits\HasBlogSeeder;
use Botble\Theme\Database\Seeders\ThemeSeeder;
use Illuminate\Support\Facades\File;

class BlogSeeder extends ThemeSeeder
{
    use HasBlogSeeder;

    public function run(): void
    {
        $this->uploadFiles('blog');
        $this->uploadFiles('users');

        $this->createBlogCategories(array_map(fn ($category) => ['name' => $category], [
            'Crisp Bread & Cake',
            'Fashion',
            'Electronic',
            'Commercial',
            'Organic Fruits',
            'Ecological',
        ]));

        $this->createBlogTags(array_map(fn ($tag) => ['name' => $tag], [
            'General',
            'Design',
            'Fashion',
            'Branding',
            'Modern',
            'Nature',
            'Vintage',
            'Sunglasses',
        ]));

        $this->createBlogPosts(array_map(function ($post) {
            $image = match ($post) {
                'Build a Better Design Process: Tips for Creative Work' => $this->filePath('blog/follow-your-own-design-process.png'),
                'Freelancing Today: Trends, Tools, and Opportunities' => $this->filePath('blog/freelancer-today.png'),
                'Smart Shopping: How to Find the Best Deals Online' => $this->filePath('blog/shopping-deals.png'),
                'Here’s the First Valentino’s New Makeup Collection' => $this->filePath('blog/valentino-makeup-collection.png'),
                'Fully Embrace the Return of 90s fashion' => $this->filePath('blog/90s-fashion-trends.png'),
                'Exploring the English Countryside' => $this->filePath('blog/english-countryside.png'),
                default => $this->filePath(sprintf('blog/post-%s.jpg', rand(1, 12))),
            };

            return [
                'name' => $post,
                'content' => File::get(database_path('seeders/contents/post.html')),
                'image' => $image,
            ];
        }, [
            '4 Expert Tips On How To Choose The Right Men’s Wallet',
            'Sexy Clutches: How to Buy & Wear a Designer Clutch Bag',
            'The Top 2020 Handbag Trends to Know',
            'How to Match the Color of Your Handbag With an Outfit',
            'How to Care for Leather Bags',
            "We're Crushing Hard on Summer's 10 Biggest Bag Trends",
            'Essential Qualities of Highly Successful Music',
            '9 Things I Love About Shaving My Head',
            'Why Teamwork Really Makes The Dream Work',
            'The World Caters to Average People',
            'The litigants on the screen are not actors',
            'Hiring the Right Sales Team at the Right Time',
            'Fully Embrace the Return of 90s fashion',
            'Exploring the English Countryside',
            'Here’s the First Valentino’s New Makeup Collection',
            'Build a Better Design Process: Tips for Creative Work',
            'Freelancing Today: Trends, Tools, and Opportunities',
            'Smart Shopping: How to Find the Best Deals Online',
        ]));
    }
}
