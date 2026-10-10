<span class="badge {{ ['high' => 'badge-red', 'medium' => 'badge-amber', 'low' => 'badge-slate'][$s] ?? 'badge-slate' }}">{{ \App\Models\FeedbackCase::SEVERITIES[$s] ?? $s }}</span>
