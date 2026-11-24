/* Payroll Pro charts: mount after each fast navigation and theme change. */
(function () {
  'use strict';
  var chart = null;
  var loading = null;
  var token = 0;
  function ensureApex() {
    if (window.ApexCharts) return Promise.resolve();
    if (loading) return loading;
    var url = document.body.getAttribute('data-pro-chart-lib');
    if (!url) return Promise.reject(new Error('Missing chart URL'));
    loading = new Promise(function (resolve, reject) {
      var el = document.createElement('script');
      el.src = url;
      el.async = true;
      el.onload = resolve;
      el.onerror = function () { loading = null; reject(new Error('Could not load chart')); };
      document.head.appendChild(el);
    });
    return loading;
  }
  function destroy() {
    token++;
    if (chart) { try { chart.destroy(); } catch (_) {} chart = null; }
  }
  async function mount() {
    destroy();
    var localToken = token;
    var el = document.querySelector('#pro-payroll-chart');
    var source = document.querySelector('#pro-payroll-chart-data');
    if (!el || !source) return;
    var data;
    try { data = JSON.parse(source.textContent || '[]'); } catch (_) { return; }
    if (!Array.isArray(data) || data.length === 0) return;
    try { await ensureApex(); } catch (_) { if (document.contains(el)) el.textContent = 'Chart unavailable; payroll records are still accessible.'; return; }
    if (localToken !== token || !document.contains(el)) return;
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var formatter = new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP',maximumFractionDigits:0});
    chart = new window.ApexCharts(el, {
      chart: {type:'bar',height:Math.max(250,data.length*51+65),toolbar:{show:false},animations:{enabled:!matchMedia('(prefers-reduced-motion: reduce)').matches,speed:310},background:'transparent',fontFamily:'inherit'},
      series: [{name:'Net pay',data:data.map(function (v) {return Number(v.net) || 0;})}],
      colors: ['#de743d'],
      plotOptions: {bar:{horizontal:true,barHeight:'43%',borderRadius:6}},
      dataLabels: {enabled:false},
      xaxis: {categories:data.map(function (v) {return String(v.name || 'Employee');}),labels:{formatter:function (v) {return formatter.format(Number(v)||0);},style:{colors:dark?'#b0b3b8':'#8291aa'}},axisBorder:{show:false},axisTicks:{show:false}},
      yaxis: {labels:{maxWidth:170,style:{colors:dark?'#e4e6eb':'#455a7b',fontSize:'12px'}}},
      grid: {borderColor:dark?'#3e4042':'#edf2f8',strokeDashArray:4},
      tooltip: {theme:dark?'dark':'light',y:{formatter:function (v) {return formatter.format(v);}}},
      legend: {show:false}
    });
    chart.render();
  }
  window.PayrollProCharts = {mount:mount,destroy:destroy};
  document.addEventListener('payroll:navigated',mount);
  document.addEventListener('payroll:theme-change',function () {if (document.querySelector('#pro-payroll-chart')) mount();});
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',mount);
  else mount();
})();
