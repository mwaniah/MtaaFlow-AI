export const CURRENCY = 'KSh';

export const formatMoney = (amount: number) =>
    `${CURRENCY} ${amount.toLocaleString('en-KE')}`;