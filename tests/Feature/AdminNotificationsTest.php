<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\NotificationCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_render_save_publish_and_cancel_campaigns(): void
    {
        config(['notifications.push_enabled'=>false]);
        $admin=User::factory()->create(['role'=>'company_admin','status'=>'active']);
        $this->actingAs($admin)->get('/admin/notifications')->assertOk()->assertSee('الإشعارات والحملات');
        $fields=['title'=>'رسالة تجريبية','body'=>'نص آمن','target_scope'=>'all'];
        $this->post('/admin/notifications',$fields)->assertRedirect();
        $campaign=NotificationCampaign::firstOrFail();
        $this->get('/admin/notifications?edit='.$campaign->id)->assertOk()->assertSee('نشر الآن');
        $this->put('/admin/notifications/'.$campaign->id,$fields+['scheduled_at'=>now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('scheduled',$campaign->fresh()->status);
        $this->post('/admin/notifications/'.$campaign->id.'/dispatch')->assertRedirect();
        $this->assertSame('dispatched',$campaign->fresh()->status);
        $this->get('/admin/notifications?edit='.$campaign->id)->assertOk()->assertDontSee('نشر الآن');
        $this->put('/admin/notifications/'.$campaign->id,$fields)->assertStatus(409);
        $this->post('/admin/notifications/'.$campaign->id.'/cancel')->assertStatus(409);
        $this->post('/admin/notifications',$fields)->assertRedirect();
        $draft=NotificationCampaign::latest('id')->firstOrFail();
        $this->post('/admin/notifications/'.$draft->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled',$draft->fresh()->status);
    }

    public function test_non_admin_cannot_access_or_publish_and_invalid_target_is_rejected(): void
    {
        $this->get('/admin/notifications')->assertRedirect('/admin/login');
        $student=User::factory()->create(['role'=>'student','status'=>'active']);
        $this->actingAs($student)->get('/admin/notifications')->assertForbidden();
        $this->post('/admin/notifications',['title'=>'x','body'=>'x','target_scope'=>'all'])->assertForbidden();
        $admin=User::factory()->create(['role'=>'company_admin','status'=>'active']);
        $this->actingAs($admin)->post('/admin/notifications',['title'=>'x','body'=>'x','target_scope'=>'teacher','target_user_id'=>$student->id])->assertSessionHasErrors('target_user_id');
        $this->assertDatabaseCount('notification_campaigns',0);
    }
}
