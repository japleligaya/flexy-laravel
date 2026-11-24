@foreach($issueGroups as $groupName => $issues)
<section class="payroll-issue-group mt-3" aria-label="{{ $groupName }}">
  <h3 class="h6">{{ $groupName }}</h3>
  @foreach($issues as $issue)
  <div class="payroll-issue">
    <div><strong>{{ $issue['title'] }}</strong><p class="mb-1">{{ $issue['explanation'] }}</p></div>
    @if($issue['canFix'])<a class="btn btn-sm btn-outline-primary" href="{{ $issue['url'] }}" data-payroll-fix>{{ $issue['action'] }}</a>
    @else <p class="small mb-0"><strong>Next action:</strong> {{ $issue['owner'] }}</p> @endif
  </div>
  @endforeach
</section>
@endforeach
