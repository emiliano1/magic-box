<?php

namespace Fuzz\MagicBox\Tests;

use Fuzz\MagicBox\Tests\Models\Tag;
use Fuzz\MagicBox\Tests\Seeds\FilterDataSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Fuzz\MagicBox\Tests\Models\User;
use Fuzz\MagicBox\Tests\Models\Post;
use Fuzz\MagicBox\EloquentRepository;
use Fuzz\MagicBox\Tests\Models\Profile;
use Illuminate\Database\Eloquent\Builder;

class EloquentRepositoryTest extends DBTestCase
{
	/**
	 * Retrieve a sample repository for testing.
	 *
	 * @param string|null $model_class
	 * @param array $input
	 * @return \Fuzz\MagicBox\EloquentRepository
	 */
	private function getRepository($model_class = null, array $input = [])
	{
		if (!is_null($model_class)) {
			return (new EloquentRepository)->setModelClass($model_class)->setDepthRestriction(3)->setInput($input);
		}

		return new EloquentRepository;
	}

	public function seedUsers()
	{
		$this->artisan->call(
			'db:seed', [
				'--class' => FilterDataSeeder::class
			]
		);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testItRejectsUnfuzzyModels()
	{
		$repo = (new EloquentRepository)->setModelClass('NotVeryFuzzy');
	}

	public function testItCanCreateASimpleModel()
	{
		$user = $this->getRepository('Fuzz\MagicBox\Tests\Models\User')->save();
		$this->assertNotNull($user);
		$this->assertEquals($user->id, 1);
	}

	public function testItCanFindASimpleModel()
	{
		$repo = $this->getRepository('Fuzz\MagicBox\Tests\Models\User');
		$user = $repo->save();
		$found_user = $repo->find($user->id);
		$this->assertNotNull($found_user);
		$this->assertEquals($user->id, $found_user->id);
	}

	public function testItCountsCollections()
	{
		$repository = $this->getRepository('Fuzz\MagicBox\Tests\Models\User');
		$this->assertEquals($repository->count(), 0);
		$this->assertFalse($repository->hasAny());
	}

	public function testItPaginates()
	{
		$repository = $this->getRepository('Fuzz\MagicBox\Tests\Models\User');
		$first_user = $repository->setInput(['username' => 'bob'])->save();
		$second_user = $repository->setInput(['username' => 'sue'])->save();

		$paginator = $repository->paginate(1);
		$this->assertInstanceOf('Illuminate\Pagination\LengthAwarePaginator', $paginator);
		$this->assertTrue($paginator->hasMorePages());
	}

	public function testItEagerLoadsRelationsSafely()
	{
		$this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'username' => 'joe',
				'posts' => [
					[
						'title' => 'Some Great Post',
					],
				]
			]
		)->save();

		$user = $this->getRepository('Fuzz\MagicBox\Tests\Models\User')->setFilters(['username' => 'joe'])
			->setEagerLoads(
				[
					'posts.nothing',
					'nada'
				]
			)->all()->first();

		$this->assertNotNull($user);
		$this->assertInstanceOf('Illuminate\Database\Eloquent\Collection', $user->posts);
		$this->assertInstanceOf('Fuzz\MagicBox\Tests\Models\Post', $user->posts->first());
	}

	public function testItCanFillModelFields()
	{
		$user = $this->getRepository('Fuzz\MagicBox\Tests\Models\User', ['username' => 'bob'])->save();
		$this->assertNotNull($user);
		$this->assertEquals($user->username, 'bob');
	}

	public function testItUpdatesExistingModels()
	{
		$user = $this->getRepository('Fuzz\MagicBox\Tests\Models\User', ['username' => 'bobby'])->save();
		$this->assertEquals($user->id, 1);
		$this->assertEquals($user->username, 'bobby');

		$user = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'id' => 1,
				'username' => 'sue'
			]
		)->save();
		$this->assertEquals($user->id, 1);
		$this->assertEquals($user->username, 'sue');
	}

	public function testItDeletesModels()
	{
		$user = $this->getRepository('Fuzz\MagicBox\Tests\Models\User', ['username' => 'spammer'])->save();
		$this->assertEquals($user->id, 1);
		$this->assertTrue($user->exists());

		$this->getRepository('Fuzz\MagicBox\Tests\Models\User', ['id' => 1])->delete();
		$this->assertNull(User::find(1));
	}

	public function testItFillsBelongsToRelations()
	{
		$post = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\Post', [
				'title' => 'Some Great Post',
				'user' => [
					'username' => 'jimmy',
				],
			]
		)->save();

		$this->assertNotNull($post->user);
		$this->assertEquals($post->user->username, 'jimmy');
	}

	public function testItFillsHasManyRelations()
	{
		$user = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'username' => 'joe',
				'posts' => [
					[
						'title' => 'Some Great Post',
					],
					[
						'title' => 'Yet Another Great Post',
					],
				]
			]
		)->save();

		$this->assertEquals(
			$user->posts->pluck('id')->toArray(), [
				1,
				2
			]
		);

		$post = Post::find(2);
		$this->assertNotNull($post);
		$this->assertEquals($post->user_id, $user->id);
		$this->assertEquals($post->title, 'Yet Another Great Post');

		$this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'id' => $user->id,
				'posts' => [
					[
						'id' => 1,
					],
				],
			]
		)->save();

		$user->load('posts');

		$this->assertEquals(
			$user->posts->pluck('id')->toArray(), [
				1,
			]
		);

		$post = Post::find(2);
		$this->assertNull($post);
	}

	public function testItFillsHasOneRelations()
	{
		$user = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'username' => 'joe',
				'profile' => [
					'favorite_cheese' => 'brie',
				],
			]
		)->save();

		$this->assertNotNull($user->profile);
		$this->assertEquals($user->profile->favorite_cheese, 'brie');
		$old_profile_id = $user->profile->id;

		$user = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\User', [
				'id' => $user->id,
				'profile' => [
					'favorite_cheese' => 'pepper jack',
				],
			]
		)->save();

		$this->assertNotNull($user->profile);
		$this->assertEquals($user->profile->favorite_cheese, 'pepper jack');

		$this->assertNotEquals($user->profile->id, $old_profile_id);
		$this->assertNull(Profile::find($old_profile_id));
	}

	public function testItCascadesThroughSupportedRelations()
	{
		$post = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\Post', [
				'title' => 'All the Tags',
				'user' => [
					'username' => 'simon',
					'profile' => [
						'favorite_cheese' => 'brie',
					],
				],
				'tags' => [
					[
						'label' => 'Important Stuff',
					],
					[
						'label' => 'Less Important Stuff',
					],
				],
			]
		)->save();

		$this->assertEquals($post->tags()->count(), 2);
		$this->assertNotNull($post->user->profile);
		$this->assertNotNull($post->user->profile->favorite_cheese, 'brie');
	}

	public function testItUpdatesBelongsToManyPivots()
	{
		$post = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\Post', [
				'title' => 'All the Tags',
				'user' => [
					'username' => 'josh',
				],
				'tags' => [
					[
						'label' => 'Has Extra',
						'pivot' => [
							'extra' => 'Meowth'
						],
					],
				],
			]
		)->save();

		$tag = $post->tags->first();
		$this->assertEquals($tag->pivot->extra, 'Meowth');

		$post = $this->getRepository(
			'Fuzz\MagicBox\Tests\Models\Post', [
				'id' => $post->id,
				'tags' => [
					[
						'id' => $tag->id,
						'pivot' => [
							'extra' => 'Pikachu',
						],
					],
				],
			]
		)->save();

		$tag = $post->tags->first();
		$this->assertEquals($tag->pivot->extra, 'Pikachu');
	}

	public function testItSorts()
	{
		$repository = $this->getRepository('Fuzz\MagicBox\Tests\Models\User');
		$first_user = $repository->setInput(
			[
				'username' => 'Bobby'
			]
		)->save();
		$second_user = $repository->setInput(
			[
				'username' => 'Robby'
			]
		)->save();
		$this->assertEquals($repository->all()->count(), 2);

		$found_users = $repository->setSortOrder(
			[
				'id' => 'desc'
			]
		)->all();
		$this->assertEquals($found_users->count(), 2);
		$this->assertEquals($found_users->first()->id, 2);
	}

	public function testItSortsNested()
	{
		$repository = $this->getRepository('Fuzz\MagicBox\Tests\Models\User');
		$first_user = $repository->setInput(
			[
				'username' => 'Bobby',
				'posts' => [
					[
						'title' => 'First Post',
						'tags' => [
							['label' => 'Tag1']
						]
					]
				]
			]
		)->save();
		$second_user = $repository->setInput(
			[
				'username' => 'Robby',
				'posts' => [
					[
						'title' => 'Zis is the final post alphabetically',
						'tags' => [
							['label' => 'Tag2']
						]
					]
				]
			]
		)->save();
		$third_user = $repository->setInput(
			[
				'username' => 'Gobby',
				'posts' => [
					[
						'title' => 'Third Post',
						'tags' => [
							['label' => 'Tag3']
						]
					]
				]
			]
		)->save();
		$this->assertEquals($repository->all()->count(), 3);

		$found_users = $repository->setSortOrder(
			[
				'posts.title' => 'desc'
			]
		)->all();
		$this->assertEquals($found_users->count(), 3);
		$this->assertEquals($found_users->first()->username, 'Robby');
	}

	public function testItModifiesQueries()
	{
		$repository = $this->getRepository('Fuzz\MagicBox\Tests\Models\User', ['username' => 'Billy']);
		$repository->save();
		$this->assertEquals($repository->count(), 1);
		$repository->setModifiers(
			[
				function (Builder $query) {
					$query->whereRaw(DB::raw('0 = 1'));
				}
			]
		);
		$this->assertEquals($repository->count(), 0);
	}

	public function testItCanFilterOnFields()
	{
		$this->seedUsers();

		// Test that the repository implements filters correctly
		$repository = $this->getRepository(User::class);
		$this->assertEquals($repository->all()->count(), 4);

		$found_users = $repository->setFilters(['username' => '=chewbaclava@galaxyfarfaraway.com'])->all();
		$this->assertEquals($found_users->count(), 1);
		$this->assertEquals($found_users->first()->username, 'chewbaclava@galaxyfarfaraway.com');
	}

	public function testItOnlyUpdatesFillableAttributesOnCreate()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
			'profile' => [
				'favorite_cheese' => 'Cheddar',
				'favorite_fruit' => 'Apples',
				'is_human' => false
			],
		];

		$user = $this->getRepository(User::class, $input)->save();
		$this->assertNull($user->not_fillable);
	}

	public function testItOnlyUpdatesFillableAttributesOnUpdate()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
			'profile' => [
				'favorite_cheese' => 'Cheddar',
				'favorite_fruit' => 'Apples',
				'is_human' => false
			],
		];

		$user = $this->getRepository(User::class, $input)->save();
		$this->assertNull($user->not_fillable);

		$input['id'] = $user->id;
		$user = $this->getRepository(User::class, $input)->update();
		$this->assertNull($user->not_fillable);
	}

	public function testItOnlyUpdatesFillableAttributesForRelationsOnCreate()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
			'profile' => [
				'favorite_cheese' => 'Cheddar',
				'favorite_fruit' => 'Apples',
				'is_human' => false,
				'not_fillable' => 'should be null'
			],
		];

		$user = $this->getRepository(User::class, $input)->save();
		$this->assertNull($user->not_fillable);
		$this->assertNull($user->profile->not_fillable);
	}

	public function testItOnlyUpdatesFillableAttributesForRelationsOnUpdate()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
			'profile' => [
				'favorite_cheese' => 'Cheddar',
				'favorite_fruit' => 'Apples',
				'is_human' => false,
				'not_fillable' => 'should be null'
			],
		];

		$user = $this->getRepository(User::class, $input)->save();
		$this->assertNull($user->not_fillable);
		$this->assertNull($user->profile->not_fillable);

		$input['id'] = $user->id;
		$user = $this->getRepository(User::class, $input)->update();
		$this->assertNull($user->not_fillable);
		$this->assertNull($user->profile->not_fillable);
	}

	public function testItDoesNotRunArbitraryMethodsOnActualInstance()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
		];

		$user = $this->getRepository(User::class, $input)->save();
		$this->assertNotNull($user);

		$input['delete'] = 'doesn\'t matter but this should not be run';
		$input['id'] = $user->id;

		// Since users are soft deletable, if this fails and we run a $user->delete(), magic box will delete the record
		// but then try to recreate it with the same ID and get a MySQL unique constraint error because the
		// original ID record exists but is soft deleted
		$user = $this->getRepository(User::class, $input)->update();

		$database_user = User::find($user->id);

		$this->assertNotNull($database_user);
		$this->assertNull($user->deleted_at);
	}

	public function testItCanSetDepthRestriction()
	{
		$input = [
			'username' => 'javacup@galaxyfarfaraway.com',
			'name' => 'Jabba The Hutt',
			'hands' => 10,
			'times_captured' => 0,
			'not_fillable' => 'should be null',
			'occupation' => 'Being Gross',
		];

		$repository = $this->getRepository(User::class, $input);
		$this->assertEquals(3, $repository->getDepthRestriction()); // getRepository sets 3 by default
		$repository->setDepthRestriction(5);
		$this->assertEquals(5, $repository->getDepthRestriction());
	}

	public function testItDepthRestrictsEagerLoads()
	{
		$this->seedUsers();

		$users = $this->getRepository(User::class)
			->setDepthRestriction(0)
			->setEagerLoads(
				[
					'posts.tags',
				]
			)->all()->toArray(); // toArray so we don't pull relations

		foreach ($users as $user) {
			$this->assertTrue(!isset($user['posts']));
			$this->assertTrue(!isset($user['posts']['tags'])); // We should load neither
		}

		$users = $this->getRepository(User::class)
			->setDepthRestriction(1)
			->setEagerLoads(
				[
					'posts',
					'posts.tags',
				]
			)->all()->toArray(); // toArray so we don't pull relations

		foreach ($users as $user) {
			$this->assertTrue(isset($user['posts']));
			$this->assertTrue(isset($user['posts'][0]));
			$this->assertTrue(!isset($user['posts'][0]['tags'])); // We should load posts (1 level) but not tags (2 levels)
		}

		$users = $this->getRepository(User::class)
			->setDepthRestriction(2)
			->setEagerLoads(
				[
					'posts',
					'posts.user',
				]
			)->all()->toArray(); // toArray so we don't pull relations

		foreach ($users as $user) {
			$this->assertTrue(isset($user['posts']));
			$this->assertTrue(isset($user['posts'][0]));
			$this->assertTrue(isset($user['posts'][0]['user'])); // We should load both
		}
	}

	public function testItDepthRestrictsFilters()
	{
		$this->seedUsers();

		/**
		 * Test with 0 depth, filter too long
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(0)
			->setFilters(
				[
					'posts.tags.label' => '=#mysonistheworst'
				]
			)
			->all();

		// Filter should not apply because depth restriction is 0
		$this->assertEquals(User::all()->count(), $users->count());

		/**
		 * Test with 1 depth, filter is allowed
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(1)
			->setFilters(
				[
					'posts.title' => '~10 Easy Ways to Clean'
				]
			)
			->all();

		// Filter should apply because depth restriction is 1
		$this->assertEquals(1, $users->count());
		$this->assertEquals('solocup@galaxyfarfaraway.com', $users->first()->username);

		/**
		 * Test with 1 depth, filter is too long
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(1)
			->setFilters(
				[
					'posts.tags.label' => '=#mysonistheworst'
				]
			)
			->all();

		// Filter should apply because depth restriction is 1
		$this->assertEquals(User::all()->count(), $users->count());

		/**
		 * Test with 1 depth, filter is okay
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(2)
			->setFilters(
				[
					'posts.tags.label' => '=#mysonistheworst'
				]
			)
			->all();

		// Filter should not apply because depth restriction is 2
		$this->assertEquals(2, $users->count());

		foreach ($users as $user) {
			$this->assertTrue(in_array($user->username, ['solocup@galaxyfarfaraway.com', 'lorgana@galaxyfarfaraway.com']));
		}
	}

	public function testItCanSortQueryAscending()
	{
		$this->seedUsers();

		$users = $this->getRepository(User::class)
			->setSortOrder(['times_captured' => 'asc'])
			->all();

		$this->assertEquals(User::all()->count(), $users->count());

		$previous_user = null;
		foreach ($users as $index => $user) {
			if ($index > 0) {
				$this->assertTrue($user->times_captured > $previous_user->times_captured);
			}

			$previous_user = $user;
		}
	}

	public function testItCanSortQueryDescending()
	{
		$this->seedUsers();

		$users = $this->getRepository(User::class)
			->setSortOrder(['times_captured' => 'desc'])
			->all();

		$this->assertEquals(User::all()->count(), $users->count());

		$previous_user = null;
		foreach ($users as $index => $user) {
			if ($index > 0) {
				$this->assertTrue($user->times_captured < $previous_user->times_captured);
			}

			$previous_user = $user;
		}
	}

	public function testItDepthRestrictsSorts()
	{
		$this->seedUsers();

		/**
		 * Sort depth zero, expect sorting by top level ID
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(0)
			->setSortOrder(['profile.favorite_cheese' => 'asc'])
			->all();

		$this->assertEquals(User::all()->count(), $users->count());

		$previous_user = null;
		foreach ($users as $index => $user) {
			if ($index > 0) {
				$this->assertTrue($user->id > $previous_user->id);
			}

			$previous_user = $user;
		}

		/**
		 * Sort depth 1, expect sorting by favorite cheese, asc alphabetical
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(1)
			->setSortOrder(['profile.favorite_cheese' => 'asc'])
			->all();

		$this->assertEquals(User::all()->count(), $users->count());

		$previous_user = null;
		$order = [];
		foreach ($users as $index => $user) {
			$order[] = $user->username;
			if ($index > 0) {
				// String 1 (Gouda) should be greater than (comes later alphabetically) than string 2 (Cheddar)
				$this->assertTrue(strcmp($user->profile->favorite_cheese, $previous_user->profile->favorite_cheese) > 0);
			}

			$previous_user = $user;
		}

		/**
		 * Sort depth 1, expect sorting by favorite cheese, desc alphabetical
		 */
		$users = $this->getRepository(User::class)
			->setDepthRestriction(1)
			->setSortOrder(['profile.favorite_cheese' => 'desc'])
			->all();

		$this->assertEquals(User::all()->count(), $users->count());

		$previous_user = null;
		foreach ($users as $index => $user) {
			if ($index > 0) {
				// String 1 (Cheddar) should be less than (comes before alphabetically) than string 2 (Gouda)
				$this->assertTrue(strcmp($user->profile->favorite_cheese, $previous_user->profile->favorite_cheese) < 0);
			}

			$previous_user = $user;
		}
	}

	public function testItCanSortBelongsToRelation()
	{
		$this->seedUsers();
		/**
		 * Sort depth 1, expect sorting by favorite cheese, asc alphabetical
		 */
		$profiles = $this->getRepository(Profile::class)
			->setSortOrder(['users.username' => 'asc'])
			->setEagerLoads(['user'])
			->all()->toArray();

		$this->assertEquals(Profile::all()->count(), count($profiles));

		$previous_profile = null;
		$order = [];
		foreach ($profiles as $index => $profile) {
			$order[] = $profile['user']['username'];
			if ($index > 0) {
				// String 1 (Gouda) should be greater than (comes later alphabetically) than string 2 (Cheddar)
				$this->assertTrue(strcmp($profile['user']['username'], $previous_profile['user']['username']) > 0);
			}

			$previous_profile = $profile;
		}
	}

	public function testItCanSortBelongsToManyRelation()
	{
		$this->seedUsers();

		/**
		 * Sort depth 1, expect sorting by favorite cheese, asc alphabetical
		 */
		$tags = $this->getRepository(Tag::class)
			->setSortOrder(['posts.title' => 'asc'])
			->setEagerLoads(['posts'])
			->all()->toArray();

		$this->assertEquals(Tag::all()->count(), count($tags));

		foreach ($tags as $index => $tag) {
			$previous_post = null;
			$order = [];
			foreach ($tag['posts'] as $post) {
				$order[] = $post['title'];
				if ($index > 0) {
					// String 1 (Gouda) should be greater than (comes later alphabetically) than string 2 (Cheddar)
					$this->assertTrue(strcmp($post['title'], $previous_post['title']) > 0);
				}

				$previous_post = $post;
			}
		}
	}

	public function testItCanAddMultipleAdditionalFilters()
	{
		$this->seedUsers();

		$repository = $this->getRepository(User::class);
		$this->assertEquals($repository->all()->count(), 4);

		$found_users = $repository->setFilters(['username' => '~galaxyfarfaraway.com'])->all();
		$this->assertEquals($found_users->count(), 4);

		$additional_filters = [
			'profile.is_human' => '=true',
			'times_captured' => '>2'
		];

		$found_users = $repository->addFilters($additional_filters)->all();
		$this->assertEquals($found_users->count(), 2);

		$filters = $repository->getFilters();
		$this->assertEquals([
			'username' => '~galaxyfarfaraway.com',
			'profile.is_human' => '=true',
			'times_captured' => '>2'
		], $filters);
	}

	public function testItCanAddOneAdditionalFilter()
	{
		$this->seedUsers();

		$repository = $this->getRepository(User::class);
		$this->assertEquals($repository->all()->count(), 4);

		$found_users = $repository->setFilters(['username' => '~galaxyfarfaraway.com'])->all();
		$this->assertEquals($found_users->count(), 4);

		$found_users = $repository->addFilter('profile.is_human', '=true')->all();
		$this->assertEquals($found_users->count(), 3);

		$filters = $repository->getFilters();
		$this->assertEquals([
			'username' => '~galaxyfarfaraway.com',
			'profile.is_human' => '=true',
		], $filters);
	}

	public function testItCanSetFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->setFillable(['foo']);

		$this->assertSame(['foo'], $repository->getFillable());
	}

	public function testItCanAddFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->addFillable('foo');

		$expect = User::FILLABLE;
		$expect[] = 'foo';

		$this->assertSame($expect, $repository->getFillable());
	}

	public function testItCanAddManyFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->addManyFillable(['foo', 'bar', 'baz']);

		$expect = User::FILLABLE;
		$expect[] = 'foo';
		$expect[] = 'bar';
		$expect[] = 'baz';

		$this->assertSame($expect, $repository->getFillable());
	}

	public function testItCanRemoveFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->setFillable([
			'foo',
			'baz',
			'bag',
		]);

		$this->assertSame([
			'foo',
			'baz',
			'bag',
		], $repository->getFillable());

		$repository->removeFillable('baz');

		$this->assertSame(['foo', 'bag'], $repository->getFillable());
	}

	public function testItCanRemoveManyFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->setFillable([
			'foo',
			'baz',
			'bag',
		]);

		$this->assertSame([
			'foo',
			'baz',
			'bag',
		], $repository->getFillable());

		$repository->removeManyFillable(['baz', 'bag']);

		$this->assertSame(['foo',], $repository->getFillable());
	}

	public function testItCanDetermineIfIsFillable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILLABLE, $repository->getFillable());

		$repository->setFillable([
			'*' // allow all
		]);

		$this->assertSame(EloquentRepository::ALLOW_ALL, $repository->getFillable());

		$this->assertTrue($repository->isFillable('foobar'));

		$repository->setFillable([
			'foo',
			'baz',
			'bag',
		]);

		$this->assertFalse($repository->isFillable('foobar'));
		$this->assertTrue($repository->isFillable('foo'));
	}

	public function testItCanSetIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$this->assertSame(['foo', 'bar', 'baz'], $repository->getIncludable());
	}

	public function testItCanAddIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$repository->addIncludable('foobar');

		$this->assertSame(['foo', 'bar', 'baz', 'foobar'], $repository->getIncludable());
	}

	public function testItCanAddManyIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$repository->addManyIncludable(['foobar', 'bazbat']);

		$this->assertSame(['foo', 'bar', 'baz', 'foobar', 'bazbat'], $repository->getIncludable());
	}

	public function testItCanRemoveIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$repository->removeIncludable('foo');

		$this->assertSame(['bar', 'baz'], $repository->getIncludable());
	}

	public function testItCanRemoveManyIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$repository->removeManyIncludable(['foo', 'bar']);

		$this->assertSame(['baz'], $repository->getIncludable());
	}

	public function testItCanDetermineIsIncludable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::INCLUDABLE, $repository->getIncludable());

		$repository->setIncludable([
			'*' // Allow all
		]);

		$this->assertSame(EloquentRepository::ALLOW_ALL, $repository->getIncludable());

		$this->assertTrue($repository->isIncludable('foobar'));

		$repository->setIncludable([
			'foo',
			'bar',
			'baz'
		]);

		$this->assertFalse($repository->isIncludable('foobar'));
		$this->assertTrue($repository->isIncludable('foo'));
	}

	public function testItCanSetFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$this->assertSame([
			'foo',
			'bar',
			'baz',
		], $repository->getFilterable());
	}

	public function testItCanAddFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$repository->addFilterable('foobar');

		$this->assertSame([
			'foo',
			'bar',
			'baz',
			'foobar',
		], $repository->getFilterable());
	}

	public function testItCanAddManyFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$repository->addManyFilterable(['foobar', 'bazbat']);

		$this->assertSame([
			'foo',
			'bar',
			'baz',
			'foobar',
			'bazbat',
		], $repository->getFilterable());
	}

	public function testItCanRemoveFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$repository->removeFilterable('bar');

		$this->assertSame(['foo', 'baz',], $repository->getFilterable());
	}

	public function testItCanRemoveManyFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$repository->removeManyFilterable(['foo', 'baz']);

		$this->assertSame(['bar',], $repository->getFilterable());
	}

	public function testItCanDetermineIsFilterable()
	{
		$repository = $this->getRepository(User::class);

		$this->assertSame(User::FILTERABLE, $repository->getFilterable());

		$repository->setFilterable([
			'*'
		]);

		$this->assertSame(EloquentRepository::ALLOW_ALL, $repository->getFilterable());

		$this->assertTrue($repository->isFilterable('foobar'));

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$this->assertFalse($repository->isFilterable('foobar'));
		$this->assertTrue($repository->isFilterable('foo'));
	}

	public function testItDoesNotFillFieldThatIsNotFillable()
	{
		$post = $this->getRepository(
				Post::class, [
				'title' => 'All the Tags',
				'not_fillable' => 'should not be set',
				'user' => [
					'username' => 'simon',
					'not_fillable' => 'should not be set',
					'profile' => [
						'favorite_cheese' => 'brie',
					],
				],
				'tags' => [
					[
						'label' => 'Important Stuff',
						'not_fillable' => 'should not be set',
					],
					[
						'label' => 'Less Important Stuff',
						'not_fillable' => 'should not be set',
					],
				],
			]
		)->save();

		$this->assertEquals($post->tags()->count(), 2);
		$this->assertNotNull($post->user->profile);
		$this->assertNotNull($post->user->profile->favorite_cheese, 'brie');

		$this->assertNull($post->not_fillable);
		$this->assertNull($post->user->not_fillable);
		$this->assertNull($post->tags->get(0)->not_fillable);
		$this->assertNull($post->tags->get(1)->not_fillable);
	}

	public function testItDoesNotIncludeRelationThatIsNotIncludable()
	{
		$this->getRepository(
			User::class, [
				'username' => 'joe',
				'posts' => [
					[
						'title' => 'Some Great Post',
					],
				]
			]
		)->save();

		$user = $this->getRepository(User::class)->setFilters(['username' => 'joe'])
			->setEagerLoads(
				[
					'posts.nothing',
					'not_exists',
					'not_includable'
				]
			)->all()->first();

		$this->assertNotNull($user);
		$this->assertInstanceOf(Collection::class, $user->posts);
		$this->assertInstanceOf(Post::class, $user->posts->first());

		$user = $user->toArray();

		$this->assertTrue(! isset($user['posts'][0]['nothing']));
		$this->assertTrue(! isset($user['not_exists']));
		$this->assertTrue(! isset($user['not_includable']));
	}

	public function testItDoesNotFilterOnWhatIsNotFilterable()
	{
		$this->seedUsers();

		// Test that the repository implements filters correctly
		$repository = $this->getRepository(User::class);
		$this->assertEquals($repository->all()->count(), 4);

		$found_users = $repository->setFilters([
			'not_filterable' => '=foo', // Should not be applied
			'posts.not_filterable' => '=foo', // Should not be applied
		])->all();
		$this->assertEquals($found_users->count(), 4); // No filters applied, expect to get all 4 users
	}

	public function testItFiltersWithAllFieldsIfAllowAllIsSet()
	{
		$this->seedUsers();

		$repository = $this->getRepository(User::class);
		$this->assertEquals($repository->all()->count(), 4);

		// Filters not applied
		$repository->setFilterable([]);
		$found_users = $repository->setFilters([
			'profile.is_human' => '=true',
			'times_captured' => '>2'
		])->all();
		$this->assertEquals($found_users->count(), 4);

		// Filters now applied
		$repository->setFilterable(EloquentRepository::ALLOW_ALL);
		$found_users = $repository->setFilters([
			'profile.is_human' => '=true',
			'times_captured' => '>2'
		])->all();
		$this->assertEquals($found_users->count(), 2);
	}

	public function testItCanGetIncludableAsAssoc()
	{
		$repository = $this->getRepository(User::class);

		$repository->setIncludable([
			'foo',
			'bar',
			'baz',
		]);

		$this->assertSame([
			'foo' => true,
			'bar' => true,
			'baz' => true,
		], $repository->getIncludable(true));
	}

	public function testItCanGetFillableAsAssoc()
	{
		$repository = $this->getRepository(User::class);

		$repository->setFillable([
			'foo',
			'bar',
			'baz',
		]);

		$this->assertSame([
			'foo' => true,
			'bar' => true,
			'baz' => true,
		], $repository->getFillable(true));
	}

	public function testItCanGetFilterableAsAssoc()
	{
		$repository = $this->getRepository(User::class);

		$repository->setFilterable([
			'foo',
			'bar',
			'baz',
		]);

		$this->assertSame([
			'foo' => true,
			'bar' => true,
			'baz' => true,
		], $repository->getFilterable(true));
	}

	public function testItCanAggregateQueryCount()
	{

	}

	public function testItCanAggregateQueryMin()
	{

	}

	public function testItCanAggregateQueryMax()
	{

	}

	public function testItCanAggregateQuerySum()
	{

	}

	public function testItCanAggregateQueryAverage()
	{

	}

	public function testItCanGroupQuery()
	{

	}

	/**
	 * @test
	 *
	 * The repository can create many models given an array of items.
	 */
	public function testItCanCreateMany()
	{
		$data = [
			['username' => 'sue'],
			['username' => 'dave'],
		];

		$users = $this->getRepository(User::class, $data)->createMany();

		$this->assertInstanceOf(Collection::class, $users);
		$this->assertEquals($users->where('username', '=', 'sue')->first()->username, 'sue');
		$this->assertEquals($users->where('username', '=', 'dave')->first()->username, 'dave');
	}

	/**
	 * @test
	 *
	 * The repository can update many models given an array of items with an id.
	 */
	public function testItCanUpdateMany()
	{
		$userOne = $this->getRepository(User::class, ['username' => 'bobby'])->save();
		$userTwo = $this->getRepository(User::class, ['username' => 'sam'])->save();
		$this->assertEquals($userOne->getKey(), 1);
		$this->assertEquals($userOne->username, 'bobby');
		$this->assertEquals($userTwo->getKey(), 2);
		$this->assertEquals($userTwo->username, 'sam');

		$users = $this->getRepository(User::class,[
			['id' => 1, 'username' => 'sue'],
			['id' => 2, 'username' => 'dave'],
		])->updateMany();

		$this->assertInstanceOf(Collection::class, $users);
		$this->assertEquals($users->find(1)->id, 1);
		$this->assertEquals($users->find(1)->username, 'sue');
		$this->assertEquals($users->find(2)->id, 2);
		$this->assertEquals($users->find(2)->username, 'dave');
	}

	/**
	 * @test
	 *
	 * The repository can check if the input should be a many operation or not.
	 */
	public function testItCanCheckIfManyOperation()
	{
		$notManyOperationData = ['id' => 1, 'username' => 'bobby'];
		$manyOperationData = [['id' => 1, 'username' => 'bobby'], ['id' => 2, 'username' => 'sam']];

		$this->assertFalse($this->getRepository(User::class, [])->isManyOperation());
		$this->assertFalse($this->getRepository(User::class, $notManyOperationData)->isManyOperation());
		$this->assertTrue($this->getRepository(User::class, $manyOperationData)->isManyOperation());
	}
}
