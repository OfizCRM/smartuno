import {
    ResponsiveContainer,
    BarChart as ReBarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
} from 'recharts';
import { useChartColors, CHART_INK } from './palette';


export default function BarChart({ data = [], xKey = 'date', yKeys = ['value'], height = 300, labels = {}, stacked = false }) {
    const COLORS = useChartColors();
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ReBarChart data={data} margin={{ top: 5, right: 16, bottom: 5, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={CHART_INK.grid} className="dark:stroke-gray-700" />
                <XAxis
                    dataKey={xKey}
                    tick={{ fontSize: 12, fill: CHART_INK.tick }}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    tick={{ fontSize: 12, fill: CHART_INK.tick }}
                    tickLine={false}
                    axisLine={false}
                    width={40}
                />
                <Tooltip
                    contentStyle={{
                        background: 'var(--tooltip-bg, #fff)',
                        border: `1px solid ${CHART_INK.grid}`,
                        borderRadius: 8,
                        fontSize: 12,
                    }}
                />
                {yKeys.length > 1 && <Legend wrapperStyle={{ fontSize: 12 }} />}
                {yKeys.map((key, i) => (
                    <Bar
                        key={key}
                        dataKey={key}
                        name={labels[key] || key}
                        fill={COLORS[i % COLORS.length]}
                        stackId={stacked ? 'stack' : undefined}
                        radius={stacked ? undefined : [3, 3, 0, 0]}
                    />
                ))}
            </ReBarChart>
        </ResponsiveContainer>
    );
}
