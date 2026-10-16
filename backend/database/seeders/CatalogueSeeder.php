<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * LOCAL / TEST ONLY. Ten additional published courses with two further lecturer accounts so the catalogue looks populated.
 * Refuses to run in production (the live academy creates its own courses in the lecturer and admin screens).
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('CatalogueSeeder refuses to run in production.');

            return;
        }
        $this->call(RolesAndPermissionsSeeder::class);
        $pw = Hash::make('Password-12345');
        $lecturer = function (string $email, string $name, string $headline, string $bio) use ($pw) {
            $u = User::firstOrNew(['email' => $email]);
            $u->forceFill(['name' => $name, 'password' => $pw, 'email_verified_at' => now(), 'status' => 'active'])->save();
            $u->roles()->syncWithoutDetaching([Role::where('slug', 'lecturer')->first()->id]);
            $u->profile()->firstOrCreate([], ['public_profile' => true, 'headline' => $headline, 'bio' => $bio]);

            return $u;
        };
        $team = [
            $lecturer('ngozi.eze@academy.test', 'Dr Ngozi Eze', 'Lecturer in Systematic Theology', 'Dr Eze teaches doctrine and church history and has served in theological education for over fifteen years.'),
            $lecturer('daniel.bello@academy.test', 'Rev. Daniel Bello', 'Pastor and ministry trainer', 'Rev. Bello pastors a growing congregation and trains pastors in preaching, leadership and pastoral care.'),
        ];
        $base = User::where('email', 'lecturer@academy.test')->first();
        if ($base) {
            $team[] = $base;
        }

        $cat = fn (string $name, string $desc, int $order) => Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'description' => $desc, 'sort_order' => $order]);
        $c = [
            'bible' => $cat('Bible Studies', 'Study of the Scriptures', 1),
            'theology' => $cat('Theology', 'Christian doctrine and its foundations', 2),
            'ministry' => $cat('Ministry and Leadership', 'Preaching, pastoral work and church administration', 3),
            'mission' => $cat('Evangelism and Missions', 'Sharing the gospel and serving the world', 4),
            'history' => $cat('Church History', 'The story of the church through the centuries', 5),
            'living' => $cat('Christian Living', 'Faith, character and daily discipleship', 6),
        ];

        // title, category, level, price in naira (0 = free), summary, description, objectives, requirement, modules => lesson titles
        $courses = [
            ['Old Testament Survey', 'bible', 'beginner', 0, 'A guided tour of the thirty-nine books of the Old Testament, from creation to the prophets.',
                'This survey follows the story of God and His people from Genesis to Malachi. You will learn the setting, message and key themes of each section of the Old Testament and see how they point to Christ.',
                ['Outline the structure and storyline of the Old Testament', 'Explain the covenants and their purpose', 'Summarise the message of the Law, History, Wisdom and Prophets'], 'No previous study is required.',
                ['Module 1: Law and History' => ['The Pentateuch and the covenant', 'Conquest, judges and kings'], 'Module 2: Wisdom and Prophets' => ['Psalms and wisdom literature', 'The prophets and the exile']]],
            ['New Testament Survey', 'bible', 'beginner', 10000, 'From the Gospels to Revelation: the message, background and structure of the New Testament.',
                'This course walks through the twenty-seven books of the New Testament. You will place each book in its historical setting and learn how the apostles explained the person and work of Jesus Christ.',
                ['Describe the background of the New Testament world', 'Summarise each major section of the New Testament', 'Trace the growth of the early church'], 'No previous study is required.',
                ['Module 1: Gospels and Acts' => ['The four Gospels', 'The book of Acts and the early church'], 'Module 2: Letters and Revelation' => ['The letters of Paul', 'General letters and Revelation']]],
            ['Doctrine of God', 'theology', 'intermediate', 15000, 'What the Bible teaches about the character, attributes and works of the Triune God.',
                'This course is the first in the systematic theology series. It examines the being and attributes of God, the doctrine of the Trinity, and God\'s work in creation and providence, always grounded in Scripture.',
                ['State the biblical basis for the Trinity', 'Explain the attributes of God and their meaning for worship', 'Distinguish creation, providence and redemption'], 'Completion of an introductory Bible course is recommended.',
                ['Module 1: Knowing God' => ['Revelation and the knowledge of God', 'The attributes of God'], 'Module 2: The Triune God' => ['The doctrine of the Trinity', 'Creation and providence']]],
            ['Biblical Hermeneutics', 'bible', 'intermediate', 15000, 'Principles and practice for interpreting the Bible faithfully and applying it wisely.',
                'Learn how to read each kind of biblical writing, from narrative and poetry to letters and prophecy. The course teaches observation, interpretation and application, and warns against common mistakes.',
                ['Apply a clear method of observation, interpretation and application', 'Recognise the main literary genres of the Bible', 'Avoid common errors of interpretation'], 'Basic familiarity with the Bible.',
                ['Module 1: Principles of Interpretation' => ['Why interpretation matters', 'Context: historical, literary and canonical'], 'Module 2: Reading the Genres' => ['Narrative and law', 'Poetry, prophecy and letters']]],
            ['Homiletics: The Art of Preaching', 'ministry', 'intermediate', 20000, 'Prepare and deliver clear, faithful sermons that connect Scripture to real life.',
                'This practical course covers sermon preparation from text to delivery. You will learn to choose a passage, find its main idea, outline a message, illustrate it, and speak with clarity and conviction.',
                ['Develop a sermon from a biblical text', 'Structure a message with a clear main idea', 'Deliver a sermon with confidence and care'], 'Experience of teaching or leading in church is helpful.',
                ['Module 1: Preparing the Message' => ['From text to main idea', 'Outlining and illustrating'], 'Module 2: Delivering the Message' => ['Voice, body and presence', 'Evaluating and improving your preaching']]],
            ['Evangelism and Personal Witness', 'mission', 'beginner', 0, 'Share your faith with confidence, in word and in everyday life.',
                'This course equips ordinary believers to talk about Jesus naturally. You will learn how to tell your story, explain the gospel simply, answer common questions and follow up with new believers.',
                ['Explain the gospel clearly and simply', 'Share a personal testimony in three minutes', 'Respond graciously to common objections'], 'No previous study is required.',
                ['Module 1: The Message' => ['The gospel in one conversation', 'Your testimony'], 'Module 2: The Method' => ['Answering questions with grace', 'Following up new believers']]],
            ['Church Leadership and Administration', 'ministry', 'intermediate', 18000, 'Lead and manage a local church with integrity, vision and good order.',
                'A practical introduction to leading a congregation. Topics include biblical models of leadership, building teams, finances and accountability, and handling conflict wisely.',
                ['Describe biblical principles of church leadership', 'Plan and organise ministry teams', 'Handle church finances with transparency and accountability'], 'Current or planned involvement in church leadership.',
                ['Module 1: Leading People' => ['Servant leadership', 'Building and caring for teams'], 'Module 2: Managing the Church' => ['Finance, records and accountability', 'Resolving conflict']]],
            ['Pastoral Care and Counselling', 'ministry', 'advanced', 25000, 'Walk alongside people in grief, crisis and struggle with biblical wisdom and practical skill.',
                'This course prepares pastors and church workers to care for people in times of need. It covers listening skills, grief and loss, marriage and family, and knowing when to refer to other professionals.',
                ['Listen well and respond with compassion', 'Support people through grief and crisis', 'Recognise the limits of pastoral counselling and refer wisely'], 'Ministry experience and completion of an introductory theology course are recommended.',
                ['Module 1: Foundations of Care' => ['The shepherd\'s heart', 'Listening and responding'], 'Module 2: Common Situations' => ['Grief, loss and crisis', 'Marriage and family care']]],
            ['Church History: The First Five Centuries', 'history', 'intermediate', 12000, 'From the apostles to the great councils: how the early church grew, suffered and defined its faith.',
                'Study the first five centuries of Christianity, including persecution, the church fathers and the councils that shaped the creeds. See how the early church handled the same questions we face today.',
                ['Trace the spread of Christianity in the Roman world', 'Explain the major heresies and the councils that answered them', 'Identify key church fathers and their contributions'], 'No previous study is required.',
                ['Module 1: A Persecuted Church' => ['Acts to the second century', 'Persecution and the apologists'], 'Module 2: Councils and Creeds' => ['Nicaea and the Trinity', 'Chalcedon and the person of Christ']]],
            ['Christian Ethics in a Modern World', 'living', 'advanced', 20000, 'Think biblically about the moral questions facing believers in family, work and society.',
                'This course gives a biblical framework for making ethical decisions. It then applies it to honesty at work, money and justice, marriage and sexuality, and the Christian\'s duty to the community and the nation.',
                ['Build an ethical framework from Scripture', 'Apply biblical ethics to work, money and justice', 'Respond with grace and conviction on contested issues'], 'Completion of an introductory theology or Bible course is recommended.',
                ['Module 1: Foundations' => ['The basis of Christian ethics', 'Conscience, law and grace'], 'Module 2: Living It Out' => ['Work, money and justice', 'Family, society and the nation']]],
        ];

        foreach ($courses as $i => [$title, $category, $level, $naira, $summary, $description, $objectives, $requirement, $modules]) {
            $slug = Str::slug($title);
            if (Course::where('slug', $slug)->exists()) {
                continue;
            }
            $lead = $team[$i % count($team)];
            $course = new Course;
            $course->forceFill(['slug' => $slug, 'title' => $title, 'summary' => $summary, 'description' => '<p>'.e($description).'</p>', 'objectives' => $objectives, 'requirements' => [$requirement], 'level' => $level,
                'category_id' => $c[$category]->id, 'status' => 'published', 'published_at' => now()->subDays(30 - $i * 2), 'price_minor' => $naira * 100, 'currency' => 'NGN', 'video_completion_percent' => 90,
                'version' => 1, 'created_by' => $lead->id])->save();
            $course->instructors()->attach($lead->id, ['role' => 'lead']);
            $mp = 0;
            foreach ($modules as $mTitle => $lessons) {
                $m = new CourseModule(['title' => $mTitle]);
                $m->course_id = $course->id;
                $m->position = ++$mp;
                $m->save();
                $lp = 0;
                foreach ($lessons as $lTitle) {
                    $l = new Lesson(['title' => $lTitle, 'type' => 'text', 'duration_seconds' => 1200, 'is_preview' => $mp === 1 && $lp === 0,
                        'content' => '<p>In this lesson you will study '.e(Str::lower($lTitle)).'. Read the assigned passages carefully, note the main ideas, and then mark the lesson complete to continue.</p>']);
                    $l->course_id = $course->id;
                    $l->module_id = $m->id;
                    $l->position = ++$lp;
                    $l->save();
                }
            }
        }
    }
}
