<?php


namespace Database\Seeders;

use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class StageSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $primary = Stage::where('name', 'primary')->first();
        $middle = Stage::where('name', 'middle')->first();
        $scientific = Stage::where('name', 'high_scientific')->first();
        $literary = Stage::where('name', 'high_literary')->first();

        $math = Subject::where('name', 'رياضيات')->first();
        $physics = Subject::where('name', 'فيزياء')->first();
        $chemistry = Subject::where('name', 'كيمياء')->first();
        $biology = Subject::where('name', 'أحياء')->first();
        $arabic = Subject::where('name', 'اللغة العربية')->first();
        $english = Subject::where('name', 'اللغة الإنجليزية')->first();
        $history = Subject::where('name', 'التاريخ')->first();
        $geography = Subject::where('name', 'الجغرافيا')->first();

        // الابتدائي والإعدادي: نفس المواد الأساسية
        foreach ([$primary, $middle] as $stage) {
            $stage->subjects()->sync([$math->id, $arabic->id, $english->id,$biology->id]);
        }

        // الثانوي العلمي: مواد علمية + عربي وإنجليزي
        $scientific->subjects()->sync([$math->id, $physics->id, $chemistry->id, $biology->id, $arabic->id, $english->id]);

        // الثانوي الأدبي: مواد أدبية + عربي وإنجليزي (بدون فيزياء/كيمياء)
        $literary->subjects()->sync([$arabic->id, $english->id, $history->id, $geography->id]);
    }
}
