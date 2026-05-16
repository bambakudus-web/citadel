// ── ADMIN DASHBOARD CHARTS ──
// Add this script to pages/admin/dashboard.php
// Requires Chart.js — add this to <head>:
// <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

// ── Add these canvas elements to the OVERVIEW section stats grid ──
// <div class="card" style="grid-column:1/-1;padding:1.5rem">
//   <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
//     <div><canvas id="chart-attendance-trend" height="200"></canvas></div>
//     <div><canvas id="chart-course-rates" height="200"></canvas></div>
//   </div>
// </div>

document.addEventListener('DOMContentLoaded', () => {
  // Only load charts when overview is active
  initCharts();
});

function initCharts() {
  const chartColors = {
    gold:    '#c9a84c',
    steel:   '#4a6fa5',
    success: '#4caf82',
    danger:  '#e05c5c',
    muted:   '#6b7a8d',
    border:  '#1a2535',
    surface: '#0c1018',
  };

  Chart.defaults.color = chartColors.muted;
  Chart.defaults.borderColor = chartColors.border;
  Chart.defaults.font.family = "'DM Sans', sans-serif";

  // ── Chart 1: Attendance Trend (last 7 sessions) ──
  fetch('../../api/attendance_stats.php?type=trend')
    .then(r => r.json())
    .then(data => {
      const ctx = document.getElementById('chart-attendance-trend');
      if (!ctx) return;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'Present/Late',
            data: data.present || [],
            borderColor: chartColors.gold,
            backgroundColor: 'rgba(201,168,76,.08)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: chartColors.gold,
            pointRadius: 4,
          }, {
            label: 'Absent',
            data: data.absent || [],
            borderColor: chartColors.danger,
            backgroundColor: 'rgba(224,92,92,.06)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: chartColors.danger,
            pointRadius: 4,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 10, padding: 16 } },
            title: { display: true, text: 'Attendance Trend — Last 10 Sessions', color: '#e8eaf0', font: { size: 13 } }
          },
          scales: {
            x: { grid: { color: chartColors.border } },
            y: { grid: { color: chartColors.border }, beginAtZero: true }
          }
        }
      });
    }).catch(() => {});

  // ── Chart 2: Per-Course Attendance Rates ──
  fetch('../../api/attendance_stats.php?type=courses')
    .then(r => r.json())
    .then(data => {
      const ctx = document.getElementById('chart-course-rates');
      if (!ctx) return;
      const rates = data.rates || [];
      const colors = rates.map(r => r >= 75 ? chartColors.success : r >= 50 ? chartColors.gold : chartColors.danger);
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'Attendance Rate %',
            data: rates,
            backgroundColor: colors.map(c => c + '33'),
            borderColor: colors,
            borderWidth: 2,
            borderRadius: 2,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            title: { display: true, text: 'Course Attendance Rates', color: '#e8eaf0', font: { size: 13 } }
          },
          scales: {
            x: { grid: { color: chartColors.border } },
            y: { grid: { color: chartColors.border }, beginAtZero: true, max: 100,
              ticks: { callback: v => v + '%' }
            }
          }
        }
      });
    }).catch(() => {});
}
