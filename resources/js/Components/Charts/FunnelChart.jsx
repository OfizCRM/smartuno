import {
    ResponsiveContainer,
    FunnelChart as ReFunnelChart,
    Funnel,
    LabelList,
    Tooltip,
} from 'recharts';
import { CHART_RAMP_BRAND, CHART_INK } from './palette';


export default function FunnelChart({ data = [], nameKey = 'name', valueKey = 'value', height = 300 }) {
    const colored = data.map((d, i) => ({ ...d, fill: CHART_RAMP_BRAND[i % CHART_RAMP_BRAND.length] }));

    return (
        <ResponsiveContainer width="100%" height={height}>
            <ReFunnelChart>
                <Tooltip
                    contentStyle={{
                        background: 'var(--tooltip-bg, #fff)',
                        border: `1px solid ${CHART_INK.grid}`,
                        borderRadius: 8,
                        fontSize: 12,
                    }}
                    formatter={(value, name) => [value.toLocaleString(), name]}
                />
                <Funnel
                    dataKey={valueKey}
                    data={colored}
                    isAnimationActive
                >
                    <LabelList
                        position="center"
                        fill="#fff"
                        fontSize={12}
                        dataKey={nameKey}
                    />
                </Funnel>
            </ReFunnelChart>
        </ResponsiveContainer>
    );
}
