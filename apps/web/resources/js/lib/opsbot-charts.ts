import type { EChartsCoreOption } from 'echarts/core';
import {
    axisTooltip,
    baseGrid,
    categoryAxis,
    legend,
    valueAxis,
} from '@/lib/charts';
import type { ChartTheme } from '@/lib/theme';

export type BarSeries = {
    name: string;
    data: number[];
    color: number;
};

function barStyle(theme: ChartTheme, color: string, radius: number[]) {
    return {
        color,
        borderRadius: radius,
        borderColor: theme.surface,
        borderWidth: 1,
    };
}

export function stackedBars(
    theme: ChartTheme,
    labels: string[],
    series: BarSeries[],
): EChartsCoreOption {
    return {
        grid: { ...baseGrid(), top: 36 },
        legend: legend(theme),
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: {
                type: 'shadow',
                shadowStyle: { color: theme.grid, opacity: 0.6 },
            },
        },
        xAxis: categoryAxis(theme, labels),
        yAxis: valueAxis(theme),
        series: series.map((item, index) => ({
            name: item.name,
            type: 'bar',
            stack: 'total',
            barMaxWidth: 28,
            data: item.data,
            emphasis: { focus: 'series' },
            itemStyle: barStyle(
                theme,
                theme.series[item.color] ?? theme.primary,
                index === series.length - 1 ? [4, 4, 0, 0] : [0, 0, 0, 0],
            ),
            label:
                index === series.length - 1
                    ? {
                          show: true,
                          position: 'top',
                          color: theme.mutedText,
                          fontSize: 11,
                          formatter: (params: { dataIndex: number }) => {
                              if (params.dataIndex !== labels.length - 1) {
                                  return '';
                              }

                              const total = series.reduce(
                                  (sum, entry) =>
                                      sum + (entry.data[params.dataIndex] ?? 0),
                                  0,
                              );

                              return total > 0 ? String(total) : '';
                          },
                      }
                    : undefined,
        })),
    };
}

export function groupedBars(
    theme: ChartTheme,
    labels: string[],
    series: BarSeries[],
): EChartsCoreOption {
    return {
        grid: { ...baseGrid(), top: 36 },
        legend: legend(theme),
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: {
                type: 'shadow',
                shadowStyle: { color: theme.grid, opacity: 0.6 },
            },
        },
        xAxis: categoryAxis(theme, labels),
        yAxis: valueAxis(theme),
        series: series.map((item) => ({
            name: item.name,
            type: 'bar',
            barGap: '10%',
            barMaxWidth: 22,
            data: item.data,
            itemStyle: barStyle(
                theme,
                theme.series[item.color] ?? theme.primary,
                [4, 4, 0, 0],
            ),
        })),
    };
}

export function horizontalBars(
    theme: ChartTheme,
    labels: string[],
    values: number[],
    name: string,
): EChartsCoreOption {
    return {
        grid: { ...baseGrid(), top: 8, right: 36 },
        tooltip: {
            ...axisTooltip(theme),
            axisPointer: {
                type: 'shadow',
                shadowStyle: { color: theme.grid, opacity: 0.6 },
            },
        },
        xAxis: {
            ...valueAxis(theme),
            splitLine: { show: false },
            axisLabel: { show: false },
        },
        yAxis: {
            type: 'category',
            data: labels,
            inverse: true,
            axisTick: { show: false },
            axisLine: { show: false },
            axisLabel: {
                color: theme.mutedText,
                fontSize: 12,
                width: 140,
                overflow: 'break',
                lineHeight: 14,
            },
        },
        series: [
            {
                name,
                type: 'bar',
                barMaxWidth: 18,
                data: values,
                itemStyle: barStyle(
                    theme,
                    theme.series[0] ?? theme.primary,
                    [0, 4, 4, 0],
                ),
                label: {
                    show: true,
                    position: 'right',
                    color: theme.text,
                    fontSize: 12,
                },
            },
        ],
    };
}
