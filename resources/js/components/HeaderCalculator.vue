<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Calculator, Check, Copy, Delete } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const open = ref(false);
const display = ref('0');
const prevValue = ref<number | null>(null);
const operator = ref<string | null>(null);
const waitingForOperand = ref(false);
const equation = ref('');
const copied = ref(false);

function inputDigit(digit: string) {
    if (waitingForOperand.value) {
        display.value = digit;
        waitingForOperand.value = false;
    } else {
        display.value = display.value === '0' ? digit : display.value + digit;
    }
}

function inputDot() {
    if (waitingForOperand.value) {
        display.value = '0.';
        waitingForOperand.value = false;
        return;
    }
    if (!display.value.includes('.')) {
        display.value += '.';
    }
}

function clear() {
    display.value = '0';
    prevValue.value = null;
    operator.value = null;
    waitingForOperand.value = false;
    equation.value = '';
}

function backspace() {
    if (waitingForOperand.value) return;
    if (display.value.length === 1 || (display.value.length === 2 && display.value.startsWith('-'))) {
        display.value = '0';
    } else {
        display.value = display.value.slice(0, -1);
    }
}

function toggleSign() {
    const val = parseFloat(display.value);
    if (isNaN(val) || val === 0) return;
    display.value = String(-val);
}

function percentage() {
    const val = parseFloat(display.value);
    if (isNaN(val)) return;
    display.value = String(val / 100);
}

function opSymbol(op: string) {
    switch (op) {
        case '+':
            return '+';
        case '-':
            return '−';
        case '*':
            return '×';
        case '/':
            return '÷';
        default:
            return op;
    }
}

function calculate(prev: number, current: number, op: string): number {
    let res = 0;
    switch (op) {
        case '+':
            res = prev + current;
            break;
        case '-':
            res = prev - current;
            break;
        case '*':
            res = prev * current;
            break;
        case '/':
            res = current === 0 ? 0 : prev / current;
            break;
        default:
            return current;
    }
    return Math.round(res * 1e10) / 1e10;
}

function performOperation(nextOperator: string) {
    const inputValue = parseFloat(display.value);

    if (prevValue.value === null) {
        prevValue.value = inputValue;
    } else if (operator.value && !waitingForOperand.value) {
        const result = calculate(prevValue.value, inputValue, operator.value);
        display.value = String(result);
        prevValue.value = result;
    }

    waitingForOperand.value = true;
    operator.value = nextOperator;
    equation.value = `${prevValue.value} ${opSymbol(nextOperator)}`;
}

function equals() {
    const inputValue = parseFloat(display.value);
    if (operator.value && prevValue.value !== null) {
        equation.value = `${prevValue.value} ${opSymbol(operator.value)} ${inputValue} =`;
        const result = calculate(prevValue.value, inputValue, operator.value);
        display.value = String(result);
        prevValue.value = null;
        operator.value = null;
        waitingForOperand.value = true;
    }
}

function copyResult() {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(display.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 1500);
}

function handleKeyDown(e: KeyboardEvent) {
    if (!open.value) return;

    if (e.key >= '0' && e.key <= '9') {
        e.preventDefault();
        inputDigit(e.key);
    } else if (e.key === '.') {
        e.preventDefault();
        inputDot();
    } else if (e.key === '+' || e.key === '-' || e.key === '*' || e.key === '/') {
        e.preventDefault();
        performOperation(e.key);
    } else if (e.key === 'Enter' || e.key === '=') {
        e.preventDefault();
        equals();
    } else if (e.key === 'Backspace') {
        e.preventDefault();
        backspace();
    } else if (e.key === 'Escape') {
        e.preventDefault();
        open.value = false;
    } else if (e.key === 'c' || e.key === 'C') {
        e.preventDefault();
        clear();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('keydown', handleKeyDown);
    } else {
        window.removeEventListener('keydown', handleKeyDown);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown);
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="press flex h-9 items-center gap-1.5 rounded-md border border-input/80 bg-card/60 px-2.5 text-xs font-medium text-foreground hover:bg-accent hover:text-accent-foreground select-none transition-colors"
                title="Calculator"
                aria-label="Open calculator"
            >
                <Calculator class="size-4 text-primary" />
                <span class="hidden xl:inline">Calculator</span>
            </button>
        </PopoverTrigger>

        <PopoverContent align="end" class="w-72 p-3 shadow-xl">
            <!-- Display -->
            <div class="mb-3 rounded-lg border border-border/60 bg-muted/40 p-2.5 text-right">
                <p class="h-4 truncate font-mono text-xs text-muted-foreground">{{ equation }}</p>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="press rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        title="Copy to clipboard"
                        @click="copyResult"
                    >
                        <Check v-if="copied" class="size-3.5 text-primary" />
                        <Copy v-else class="size-3.5" />
                    </button>
                    <span class="truncate font-mono text-2xl font-bold tracking-tight text-foreground select-all">
                        {{ display }}
                    </span>
                </div>
            </div>

            <!-- Keypad -->
            <div class="grid grid-cols-4 gap-1.5">
                <!-- Row 1 -->
                <Button variant="secondary" size="sm" class="h-9 font-medium text-destructive hover:bg-destructive/10 hover:text-destructive" @click="clear">
                    AC
                </Button>
                <Button variant="secondary" size="sm" class="h-9 font-medium" @click="toggleSign">
                    ±
                </Button>
                <Button variant="secondary" size="sm" class="h-9 font-medium" @click="percentage">
                    %
                </Button>
                <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('/')">
                    ÷
                </Button>

                <!-- Row 2 -->
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('7')">7</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('8')">8</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('9')">9</Button>
                <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('*')">
                    ×
                </Button>

                <!-- Row 3 -->
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('4')">4</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('5')">5</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('6')">6</Button>
                <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('-')">
                    −
                </Button>

                <!-- Row 4 -->
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('1')">1</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('2')">2</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('3')">3</Button>
                <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('+')">
                    +
                </Button>

                <!-- Row 5 -->
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('0')">0</Button>
                <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDot">.</Button>
                <Button variant="ghost" size="sm" class="h-9 text-muted-foreground hover:text-foreground" title="Backspace" @click="backspace">
                    <Delete class="size-4" />
                </Button>
                <Button size="sm" class="h-9 bg-primary font-bold text-primary-foreground hover:bg-primary/90" @click="equals">
                    =
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>

