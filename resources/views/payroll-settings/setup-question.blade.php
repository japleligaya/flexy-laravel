<div class="payroll-setup-choice my-3">
  <label for="setup-{{ $key }}" class="form-label">{{ $label }}</label>
  <p class="payroll-help" id="help-{{ $key }}">{{ $help }}</p>
  <select id="setup-{{ $key }}" name="{{ $key }}" class="form-select @error($key) is-invalid @enderror" aria-describedby="help-{{ $key }}" required data-summary-label="{{ $label }}">
    <option value="">Choose your company’s answer</option>
    @foreach($options as $value=>$text)<option value="{{ $value }}" @selected((string)old($key,$policy[$key]??'')===(string)$value)>{{ $text }}</option> @endforeach
  </select>
  @error($key)<p class="text-danger small mt-1">{{ $message }}</p> @enderror
</div>
