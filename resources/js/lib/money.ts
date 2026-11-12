import { i18n } from './i18n';
export const CURRENCY = 'KSh';

export const formatMoney = (amount: number) =>
    `${CURRENCY} ${amount.toLocaleString(i18n.global.locale.value)}`;
